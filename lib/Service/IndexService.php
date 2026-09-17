<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoSweep\Service;

use OCA\PhotoSweep\Db\Media;
use OCA\PhotoSweep\Db\MediaMapper;
use OCA\PhotoSweep\Db\Scan;
use OCA\PhotoSweep\Db\ScanMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Builds and maintains the month index.
 *
 * The scan is resumable by design. A first pass over a large library is minutes of
 * work, and it runs in a background job that can be cut short at any point, so
 * progress is written after every batch and picked up from a file-id cursor on the
 * next run. Losing the process costs one batch, never the whole scan.
 */
class IndexService {

	/** Files per database round trip. Large enough to amortise, small enough to resume. */
	private const BATCH_SIZE = 500;

	/**
	 * Batches a browser-triggered scan does before handing back.
	 *
	 * Much smaller than the cron budget on purpose: the request has to return inside
	 * the web server's timeout, and the UI would rather show real progress four
	 * thousand files at a time than sit on a spinner and then fail.
	 */
	public const WEB_BATCHES = 8;

	/**
	 * Batches per run, so one user's first scan cannot monopolise cron.
	 * At 500 a batch this indexes 20,000 files per run and resumes on the next.
	 */
	private const MAX_BATCHES_PER_RUN = 40;

	/**
	 * How long a `running` flag is believed before it is treated as a crashed run.
	 * Without this a process killed mid-scan would lock the user out of scanning for good.
	 */
	private const STALE_RUN_SECONDS = 1800;

	public function __construct(
		private IRootFolder $rootFolder,
		private MediaMapper $mediaMapper,
		private ScanMapper $scanMapper,
		private MediaFinder $finder,
		private DateResolver $dateResolver,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	public function getStatus(string $userId): Scan {
		return $this->scanMapper->findOrCreate($userId);
	}

	public function isIndexed(string $userId): bool {
		$scan = $this->scanMapper->find($userId);
		return $scan !== null && $scan->getComplete();
	}

	/**
	 * Brings the index up to date, resuming an interrupted pass or starting a new one.
	 *
	 * @param bool $full discard the index and re-read everything, which is how a user
	 *                   corrects drift after reorganising files outside the app
	 * @param null|callable(int, int): void $onProgress receives (indexed so far, batch size)
	 * @param int|null $maxBatches cap for this run; a web request needs a much tighter
	 *                             one than cron does, so the caller sets it
	 */
	public function scan(
		string $userId,
		bool $full = false,
		?callable $onProgress = null,
		?int $maxBatches = null,
	): Scan {
		$scan = $this->scanMapper->findOrCreate($userId);
		$now = time();

		if ($scan->getRunning() && ($now - $scan->getUpdatedAt()) < self::STALE_RUN_SECONDS) {
			return $scan;
		}

		if ($full) {
			$this->mediaMapper->removeAllForUser($userId);
			$scan->setCursorOffset(0);
			$scan->setFound(0);
			$scan->setComplete(false);
		}

		// A completed index is refreshed by walking it again from the start rather than
		// carrying on from where it stopped: resuming would only ever find new
		// uploads and would never notice a file that left.
		if ($scan->getComplete() && !$full) {
			$scan->setCursorOffset(0);
			$scan->setFound(0);
			$scan->setComplete(false);
		}

		$scan->setRunning(true);
		$scan->setError(null);
		if ($scan->getCursorOffset() === 0) {
			$scan->setStartedAt($now);
		}
		$scan->setUpdatedAt($now);
		$this->scanMapper->save($scan);

		try {
			$this->runScan($userId, $scan, $onProgress, $maxBatches ?? self::MAX_BATCHES_PER_RUN);
		} catch (\Throwable $e) {
			$this->logger->error('Photo Sweep index scan failed', [
				'exception' => $e,
				'userId' => $userId,
				'app' => 'photosweep',
			]);
			$scan->setError(mb_substr($e->getMessage(), 0, 250));
		} finally {
			$scan->setRunning(false);
			$scan->setUpdatedAt(time());
			$this->scanMapper->save($scan);
		}

		return $scan;
	}

	/**
	 * @param null|callable(int, int): void $onProgress
	 */
	private function runScan(string $userId, Scan $scan, ?callable $onProgress, int $maxBatches): void {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$scope = $this->resolveScope($userFolder, $this->configService->getSourceFolder($userId));
		$timezone = $this->configService->getTimeZone($userId);

		// Whatever folder mode moves files into is not part of the library any more.
		// Indexing it would put condemned photos back in front of the user, month
		// after month, which is the one outcome that makes the feature useless.
		$excludedPrefix = rtrim($this->configService->getTargetFolder($userId), '/') . '/';

		$batches = 0;
		while ($batches++ < $maxBatches) {
			$files = $this->finder->findBatch($scope, $scan->getCursorOffset(), self::BATCH_SIZE);
			if ($files === []) {
				$this->finishPass($userId, $scan);
				break;
			}

			$indexed = $this->indexBatch($userId, $userFolder, $files, $timezone, $excludedPrefix);

			$scan->setCursorOffset($scan->getCursorOffset() + count($files));
			$scan->setFound($scan->getFound() + $indexed);
			$scan->setUpdatedAt(time());
			// Written every batch: this is what makes the scan resumable, and what
			// makes months appear in the grid while it is still running.
			$this->scanMapper->save($scan);

			if ($onProgress !== null) {
				$onProgress($scan->getFound(), count($files));
			}

			if (count($files) < self::BATCH_SIZE) {
				$this->finishPass($userId, $scan);
				break;
			}
		}
	}

	/**
	 * Closes out a pass that reached the end of the library.
	 *
	 * Everything still present was re-stamped during this pass, so anything older than
	 * the pass is a file that has gone — deleted while the app was off, moved out of
	 * the indexed folder, or moved into the folder that mode excludes.
	 */
	private function finishPass(string $userId, Scan $scan): void {
		$removed = $this->mediaMapper->removeStale($userId, $scan->getStartedAt());
		if ($removed > 0) {
			$this->logger->info('Removed stale entries after a full index pass', [
				'removed' => $removed,
				'app' => 'photosweep',
			]);
		}
		$scan->setComplete(true);
	}

	/**
	 * @param File[] $files
	 * @return int how many were actually indexed
	 */
	private function indexBatch(
		string $userId,
		Folder $userFolder,
		array $files,
		\DateTimeZone $timezone,
		string $excludedPrefix,
	): int {
		$fileIds = array_map(static fn (File $f): int => $f->getId(), $files);
		$metadata = $this->dateResolver->preloadMetadata($fileIds);
		$existing = $this->mediaMapper->existingRowIds($userId, $fileIds);

		$indexed = 0;
		foreach ($files as $file) {
			try {
				$relativePath = $userFolder->getRelativePath($file->getPath());
				if ($relativePath === null) {
					continue;
				}
				if ($excludedPrefix !== '/' && str_starts_with($relativePath, $excludedPrefix)) {
					continue;
				}

				$resolved = $this->dateResolver->resolve($file, $timezone, $metadata);

				$media = new Media();
				$media->setUserId($userId);
				$media->setFileId($file->getId());
				$media->setTakenAt($resolved->timestamp);
				$media->setYearMonth(self::yearMonth($resolved->timestamp, $timezone));
				$media->setDateSource($resolved->source);
				$media->setMimetype($file->getMimeType());
				$media->setIsVideo(str_starts_with($file->getMimeType(), 'video/'));
				$media->setName($file->getName());
				$media->setPath($relativePath);
				$media->setSize(max(0, (int)$file->getSize()));
				$media->setIndexedAt(time());

				$rowId = $existing[$file->getId()] ?? null;
				if ($rowId !== null) {
					$media->setId($rowId);
					$this->mediaMapper->update($media);
				} else {
					$this->mediaMapper->insert($media);
				}
				$indexed++;
			} catch (\Throwable $e) {
				// One unreadable file must not abort the scan; the rest of the library
				// is still worth indexing.
				$this->logger->debug('Skipped a file while indexing', [
					'exception' => $e,
					'app' => 'photosweep',
				]);
			}
		}

		return $indexed;
	}

	/**
	 * The folder to index, falling back to everything if the configured one is gone.
	 */
	private function resolveScope(Folder $userFolder, string $sourcePath): Folder {
		if ($sourcePath === '' || $sourcePath === '/') {
			return $userFolder;
		}
		try {
			$node = $userFolder->get($sourcePath);
			if ($node instanceof Folder) {
				return $node;
			}
		} catch (NotFoundException $e) {
			// Configured folder has been renamed or removed.
		}
		$this->logger->warning('Configured source folder is missing, indexing everything instead', [
			'path' => $sourcePath,
			'app' => 'photosweep',
		]);
		return $userFolder;
	}

	/**
	 * Indexes a specific set of files, adding them back to the library.
	 *
	 * Applying a verdict takes a file out of the index because it has left the
	 * library; restoring one has to put it back, or the photo would sit on disk
	 * invisible to the month grid until somebody happened to run a full rebuild.
	 *
	 * @param int[] $fileIds
	 * @return int how many were indexed
	 */
	public function indexFiles(string $userId, array $fileIds): int {
		if ($fileIds === []) {
			return 0;
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$timezone = $this->configService->getTimeZone($userId);
		$excludedPrefix = rtrim($this->configService->getTargetFolder($userId), '/') . '/';

		$files = [];
		foreach ($fileIds as $fileId) {
			$node = null;
			if (method_exists($userFolder, 'getFirstNodeById')) {
				$node = $userFolder->getFirstNodeById($fileId);
			} else {
				$node = $userFolder->getById($fileId)[0] ?? null;
			}
			if ($node instanceof File) {
				$files[] = $node;
			}
		}

		return $this->indexBatch($userId, $userFolder, $files, $timezone, $excludedPrefix);
	}

	/** Drops one file out of the index, for when it leaves the library. */
	public function forget(string $userId, int $fileId): void {
		$this->mediaMapper->removeByFileId($userId, $fileId);
	}

	/**
	 * @param int[] $fileIds
	 */
	public function forgetMany(string $userId, array $fileIds): void {
		$this->mediaMapper->removeByFileIds($userId, $fileIds);
	}

	/** "2024-07", in the user's own timezone. */
	public static function yearMonth(int $timestamp, \DateTimeZone $timezone): string {
		return (new \DateTimeImmutable('@' . $timestamp))
			->setTimezone($timezone)
			->format('Y-m');
	}
}
