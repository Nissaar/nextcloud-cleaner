<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Service;

use OCA\PhotoCleaner\Db\Decision;
use OCA\PhotoCleaner\Db\DecisionMapper;
use OCA\PhotoCleaner\Db\MediaMapper;
use OCA\PhotoCleaner\Db\Verdict;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

/**
 * The only part of the app that changes files.
 *
 * Two rules hold throughout. A verdict is marked applied only *after* the file has
 * actually moved, so an interrupted run leaves work pending and safe to retry rather
 * than claiming something it did not do. And each file is handled on its own, so one
 * permission error costs one photo instead of the whole batch.
 */
class CleanupService {

	public function __construct(
		private IRootFolder $rootFolder,
		private DecisionMapper $decisionMapper,
		private MediaMapper $mediaMapper,
		private ConfigService $configService,
		private TrashService $trashService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Carries out every pending DELETE verdict.
	 *
	 * Callers are responsible for having asked first — nothing here confirms anything.
	 *
	 * @param null|callable(int, int): void $onProgress receives (done, total)
	 */
	public function apply(string $userId, ?callable $onProgress = null): ApplyResult {
		$pending = $this->decisionMapper->findPending($userId, Verdict::DELETE);
		$mode = $this->configService->getMode($userId);

		if ($pending === []) {
			return new ApplyResult($mode, 0);
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$target = null;
		if ($mode === CleanupMode::FOLDER) {
			try {
				$target = $this->ensureTargetFolder($userFolder, $this->configService->getTargetFolder($userId));
			} catch (\Throwable $e) {
				return new ApplyResult(
					$mode,
					0,
					[],
					$this->configService->getTargetFolder($userId),
					'Could not create the destination folder: ' . $e->getMessage(),
				);
			}
		}

		$total = count($pending);
		$done = 0;
		$succeededIds = [];
		$failures = [];

		foreach ($pending as $decision) {
			$done++;
			try {
				$file = $this->findFile($userFolder, $decision->getFileId());
				if ($file === null) {
					// Already gone — someone deleted it elsewhere. The verdict has
					// effectively been carried out, so record it rather than nagging
					// about it every time the review screen opens.
					$succeededIds[] = $decision->getFileId();
					continue;
				}

				if ($mode === CleanupMode::TRASH) {
					$file->delete();
				} else {
					/** @var Folder $target */
					$file->move($target->getPath() . '/' . $this->uniqueName($target, $file->getName()));
				}
				$succeededIds[] = $decision->getFileId();
			} catch (NotPermittedException $e) {
				$failures[$decision->getFileId()] = 'You do not have permission to change this file';
			} catch (\Throwable $e) {
				$this->logger->warning('Could not apply a verdict', [
					'exception' => $e,
					'fileId' => $decision->getFileId(),
					'app' => 'photocleaner',
				]);
				$failures[$decision->getFileId()] = $e->getMessage();
			} finally {
				if ($onProgress !== null) {
					$onProgress($done, $total);
				}
			}
		}

		if ($succeededIds !== []) {
			$this->decisionMapper->markApplied($userId, $succeededIds, time(), $mode);
			// They have left the library, so they must leave the month grid too.
			$this->mediaMapper->removeByFileIds($userId, $succeededIds);
		}

		return new ApplyResult(
			$mode,
			count($succeededIds),
			$failures,
			$target === null ? null : $userFolder->getRelativePath($target->getPath()),
		);
	}

	/**
	 * Puts already-applied items back.
	 *
	 * Trashed files come back through the trash, moved files are moved home. Either
	 * way the verdict is dropped entirely rather than flipped to KEEP: the photo
	 * returns to the library undecided, which is what "I changed my mind" means.
	 *
	 * @param int[] $fileIds
	 * @return array{restored: int, failures: array<int, string>}
	 */
	public function restore(string $userId, array $fileIds): array {
		if ($fileIds === []) {
			return ['restored' => 0, 'failures' => []];
		}

		$decisions = array_filter(
			$this->decisionMapper->findByFileIds($userId, $fileIds),
			static fn (Decision $d): bool => $d->getApplied(),
		);

		$byMode = [CleanupMode::TRASH => [], CleanupMode::FOLDER => []];
		foreach ($decisions as $decision) {
			$mode = $decision->getAppliedMode() ?? CleanupMode::TRASH;
			$byMode[$mode][] = $decision;
		}

		$restoredIds = [];
		$failures = [];

		if ($byMode[CleanupMode::TRASH] !== []) {
			$wanted = array_map(static fn (Decision $d): int => $d->getFileId(), $byMode[CleanupMode::TRASH]);
			if (!$this->trashService->isAvailable()) {
				foreach ($wanted as $id) {
					$failures[$id] = 'The trash is disabled on this server, so this file cannot be brought back';
				}
			} else {
				$restored = $this->trashService->restore($userId, $wanted);
				$restoredIds = array_merge($restoredIds, $restored);
				foreach (array_diff($wanted, $restored) as $id) {
					$failures[$id] = 'No longer in the trash — it may have been emptied';
				}
			}
		}

		if ($byMode[CleanupMode::FOLDER] !== []) {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			foreach ($byMode[CleanupMode::FOLDER] as $decision) {
				try {
					$this->moveBack($userFolder, $decision);
					$restoredIds[] = $decision->getFileId();
				} catch (\Throwable $e) {
					$failures[$decision->getFileId()] = $e->getMessage();
				}
			}
		}

		if ($restoredIds !== []) {
			$this->decisionMapper->removeByFileIds($userId, $restoredIds);
		}

		return ['restored' => count($restoredIds), 'failures' => $failures];
	}

	/**
	 * @throws NotFoundException
	 */
	private function moveBack(Folder $userFolder, Decision $decision): void {
		$file = $this->findFile($userFolder, $decision->getFileId());
		if ($file === null) {
			throw new NotFoundException('The file is no longer where it was put');
		}

		$origin = $decision->getOriginPath();
		if ($origin === null || $origin === '') {
			throw new NotFoundException('The original location was not recorded');
		}

		// dirname() answers "." for a file that lived at the top of the library, which
		// ensureTargetFolder would then try to create as a folder called ".".
		$parentPath = dirname('/' . ltrim($origin, '/'));
		if ($parentPath === '.') {
			$parentPath = '/';
		}
		$parent = $this->ensureTargetFolder($userFolder, $parentPath);
		$file->move($parent->getPath() . '/' . $this->uniqueName($parent, basename($origin)));
	}

	/**
	 * Finds a file by id, or null if it has gone.
	 *
	 * `getFirstNodeById` is the direct route but only exists on newer servers, so the
	 * older list form is kept as a fallback rather than raising the minimum version
	 * over one method.
	 */
	private function findFile(Folder $userFolder, int $fileId): ?File {
		$node = null;
		if (method_exists($userFolder, 'getFirstNodeById')) {
			$node = $userFolder->getFirstNodeById($fileId);
		} else {
			$nodes = $userFolder->getById($fileId);
			$node = $nodes[0] ?? null;
		}
		return $node instanceof File ? $node : null;
	}

	/**
	 * Returns the folder at [$path], creating it and any missing parents.
	 */
	private function ensureTargetFolder(Folder $userFolder, string $path): Folder {
		$path = '/' . trim($path, '/');
		if ($path === '/') {
			return $userFolder;
		}

		try {
			$node = $userFolder->get($path);
			if ($node instanceof Folder) {
				return $node;
			}
			throw new NotPermittedException('A file already exists at ' . $path);
		} catch (NotFoundException $e) {
			// Create it below.
		}

		$current = $userFolder;
		foreach (array_filter(explode('/', $path), static fn (string $p): bool => $p !== '') as $segment) {
			try {
				$next = $current->get($segment);
			} catch (NotFoundException $e) {
				$next = $current->newFolder($segment);
			}
			if (!$next instanceof Folder) {
				throw new NotPermittedException('A file already exists at ' . $next->getPath());
			}
			$current = $next;
		}
		return $current;
	}

	/**
	 * A name that does not collide inside [$folder].
	 *
	 * Two photos called IMG_0001.jpg from different folders end up side by side once
	 * they are collected, and silently overwriting one of them would destroy the very
	 * file the mode exists to preserve.
	 */
	private function uniqueName(Folder $folder, string $name): string {
		if (!$folder->nodeExists($name)) {
			return $name;
		}

		$extension = pathinfo($name, PATHINFO_EXTENSION);
		$stem = $extension === '' ? $name : substr($name, 0, -(strlen($extension) + 1));
		$suffix = $extension === '' ? '' : '.' . $extension;

		for ($n = 2; $n < 1000; $n++) {
			$candidate = $stem . ' (' . $n . ')' . $suffix;
			if (!$folder->nodeExists($candidate)) {
				return $candidate;
			}
		}

		return $stem . ' (' . substr(bin2hex(random_bytes(4)), 0, 8) . ')' . $suffix;
	}
}
