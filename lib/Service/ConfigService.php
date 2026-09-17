<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudCleaner\Service;

use OCA\NextcloudCleaner\AppInfo\Application;
use OCP\IConfig;

/**
 * How a DELETE verdict is carried out.
 */
final class CleanupMode {
	/**
	 * Move the file to the Nextcloud trash. A real deletion: it leaves the library and
	 * the storage comes back when the server's retention policy expires it, and it is
	 * recoverable until then.
	 */
	public const TRASH = 'trash';

	/**
	 * Non-destructive: move the file into a folder instead, so it can be checked in
	 * Files and deleted by hand. Nothing is ever lost, but nothing is freed either.
	 */
	public const FOLDER = 'folder';

	public static function isValid(string $mode): bool {
		return $mode === self::TRASH || $mode === self::FOLDER;
	}
}

/**
 * Per-user settings, read straight from Nextcloud's own user config.
 */
class ConfigService {

	public const KEY_MODE = 'cleanup_mode';
	public const KEY_TARGET_FOLDER = 'target_folder';
	public const KEY_SOURCE_FOLDER = 'source_folder';
	public const KEY_SKIP_DECIDED = 'skip_decided';

	public const DEFAULT_TARGET_FOLDER = '/To Be Deleted';
	public const DEFAULT_SOURCE_FOLDER = '/';

	public function __construct(
		private IConfig $config,
	) {
	}

	public function getMode(string $userId): string {
		$mode = $this->config->getUserValue($userId, Application::APP_ID, self::KEY_MODE, CleanupMode::TRASH);
		return CleanupMode::isValid($mode) ? $mode : CleanupMode::TRASH;
	}

	public function setMode(string $userId, string $mode): void {
		if (!CleanupMode::isValid($mode)) {
			throw new \InvalidArgumentException('Unknown cleanup mode: ' . $mode);
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_MODE, $mode);
	}

	/** Where folder-mode puts condemned files. */
	public function getTargetFolder(string $userId): string {
		$path = $this->config->getUserValue(
			$userId,
			Application::APP_ID,
			self::KEY_TARGET_FOLDER,
			self::DEFAULT_TARGET_FOLDER,
		);
		return $this->normalisePath($path, self::DEFAULT_TARGET_FOLDER);
	}

	public function setTargetFolder(string $userId, string $path): void {
		$normalised = $this->normalisePath($path, self::DEFAULT_TARGET_FOLDER);
		if ($normalised === '/') {
			throw new \InvalidArgumentException('The target folder cannot be the root of your files');
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_TARGET_FOLDER, $normalised);
	}

	/** Which part of the user's files gets indexed. */
	public function getSourceFolder(string $userId): string {
		$path = $this->config->getUserValue(
			$userId,
			Application::APP_ID,
			self::KEY_SOURCE_FOLDER,
			self::DEFAULT_SOURCE_FOLDER,
		);
		return $this->normalisePath($path, self::DEFAULT_SOURCE_FOLDER);
	}

	public function setSourceFolder(string $userId, string $path): void {
		$this->config->setUserValue(
			$userId,
			Application::APP_ID,
			self::KEY_SOURCE_FOLDER,
			$this->normalisePath($path, self::DEFAULT_SOURCE_FOLDER),
		);
	}

	/** Hide items you have already judged when a month is reopened. */
	public function getSkipDecided(string $userId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, self::KEY_SKIP_DECIDED, '1') === '1';
	}

	public function setSkipDecided(string $userId, bool $value): void {
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_SKIP_DECIDED, $value ? '1' : '0');
	}

	/**
	 * The user's own timezone, which is what months are bucketed in.
	 *
	 * Mirrors how core resolves it, so a photo lands in the same month here as it does
	 * everywhere else in Nextcloud. Falls back to the server default, then UTC, because
	 * an unset or nonsense timezone must not take the indexer down mid-scan.
	 */
	public function getTimeZone(string $userId): \DateTimeZone {
		$name = $this->config->getUserValue($userId, 'core', 'timezone', '');
		if ($name !== '') {
			$zone = $this->parseZone($name);
			if ($zone !== null) {
				return $zone;
			}
		}

		$serverDefault = (string)$this->config->getSystemValue('default_timezone', 'UTC');
		return $this->parseZone($serverDefault) ?? new \DateTimeZone('UTC');
	}

	private function parseZone(string $name): ?\DateTimeZone {
		try {
			return new \DateTimeZone($name);
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function asArray(string $userId): array {
		return [
			'mode' => $this->getMode($userId),
			'targetFolder' => $this->getTargetFolder($userId),
			'sourceFolder' => $this->getSourceFolder($userId),
			'skipDecided' => $this->getSkipDecided($userId),
			'timezone' => $this->getTimeZone($userId)->getName(),
		];
	}

	/**
	 * Forces a user-supplied path into the shape the Files API expects: leading slash,
	 * no trailing slash, no traversal.
	 */
	private function normalisePath(string $path, string $fallback): string {
		$path = trim($path);
		if ($path === '') {
			return $fallback;
		}
		$path = '/' . trim(str_replace('\\', '/', $path), '/');
		if (str_contains($path, '..')) {
			return $fallback;
		}
		return $path;
	}
}
