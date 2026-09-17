<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoSweep\Service;

use OCA\PhotoSweep\Db\DateSource;
use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use Psr\Log\LoggerInterface;

/**
 * The resolved capture date of one file, and where it came from.
 */
class ResolvedDate {
	public function __construct(
		public readonly int $timestamp,
		public readonly string $source,
	) {
	}
}

/**
 * Works out when a photo was actually taken.
 *
 * "Date taken" is the whole premise of the month grid, and Nextcloud does not store it
 * as a first-class field. The Photos app extracts it from EXIF into file metadata,
 * which is the good case — but Photos can be disabled, metadata generation can lag
 * behind an upload, and plenty of files have no EXIF at all.
 *
 * So this walks a chain, best evidence first, and records which link answered. That
 * last part matters: a screenshot landing in the month it was copied rather than taken
 * is confusing until you can see the app is telling you it only had an mtime to go on.
 */
class DateResolver {

	/** Set by the Photos app from EXIF DateTimeOriginal, falling back to name and mtime. */
	public const METADATA_KEY_ORIGINAL_DATE_TIME = 'photos-original_date_time';
	/** Raw EXIF block, used here only to tell a real capture date from a fallback. */
	public const METADATA_KEY_EXIF = 'photos-exif';

	/**
	 * Camera and screenshot naming conventions, in the order they are tried.
	 *
	 * The first two match what the Photos app itself parses, so a library indexed by
	 * both agrees with itself. The rest cover the common exports that Photos skips.
	 */
	private const FILENAME_PATTERNS = [
		// IMG_20240712_140325, PXL_20240712_140325123, VID_20240712_140325, Screenshot_20240712-140325
		'/(?:^|[^0-9])(\d{8})[_-](\d{6})\d{0,3}(?:[^0-9]|$)/' => 'Ymd His',
		// 2024-07-12-14-03-25
		'/(?:^|[^0-9])(\d{4}-\d{2}-\d{2})-(\d{2}-\d{2}-\d{2})/' => 'Y-m-d H-i-s',
		// 2024-07-12 14.03.25 / 2024_07_12 14_03_25
		'/(?:^|[^0-9])(\d{4}[_-]\d{2}[_-]\d{2})[ _](\d{2}[._-]\d{2}[._-]\d{2})/' => 'Y?m?d H?i?s',
		// Date only: 2024-07-12, 20240712
		'/(?:^|[^0-9])(\d{4}-\d{2}-\d{2})(?:[^0-9]|$)/' => 'Y-m-d',
		'/(?:^|[^0-9])(\d{8})(?:[^0-9]|$)/' => 'Ymd',
	];

	/**
	 * Dates outside this range are rejected rather than trusted.
	 *
	 * A filename full of digits is not necessarily a date — an eight-digit serial
	 * number parses happily into the year 8402 — and one bad parse creates a phantom
	 * month in the grid that the user cannot explain or get rid of.
	 */
	private const EARLIEST_PLAUSIBLE = 504921600; // 1986-01-01
	private const FUTURE_TOLERANCE = 86400 * 2;

	public function __construct(
		private IFilesMetadataManager $metadataManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Pre-fetches metadata for a whole batch.
	 *
	 * One query for five hundred files instead of five hundred queries; on a large
	 * library that is the difference between a scan measured in seconds and one
	 * measured in minutes.
	 *
	 * @param int[] $fileIds
	 * @return array<int, IFilesMetadata>
	 */
	public function preloadMetadata(array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}
		try {
			return $this->metadataManager->getMetadataForFiles($fileIds);
		} catch (\Throwable $e) {
			$this->logger->debug('Could not preload file metadata', [
				'exception' => $e,
				'app' => 'photosweep',
			]);
			return [];
		}
	}

	/**
	 * @param array<int, IFilesMetadata> $preloaded from {@see preloadMetadata}
	 */
	public function resolve(File $file, \DateTimeZone $timezone, array $preloaded = []): ResolvedDate {
		$metadata = $preloaded[$file->getId()] ?? null;

		$exifTaken = $this->fromExif($metadata, $timezone);
		if ($exifTaken !== null) {
			return new ResolvedDate($exifTaken, DateSource::EXIF);
		}

		$nameTaken = $this->fromFilename($file->getName(), $timezone);
		if ($nameTaken !== null) {
			return new ResolvedDate($nameTaken, DateSource::FILENAME);
		}

		// The Photos app's own value, which at this point is its mtime fallback —
		// we already know there was no usable EXIF or filename date.
		$metaTaken = $this->fromMetadataTimestamp($metadata);
		if ($metaTaken !== null) {
			return new ResolvedDate($metaTaken, DateSource::MTIME);
		}

		$mtime = $file->getMTime();
		if ($this->isPlausible($mtime)) {
			return new ResolvedDate($mtime, DateSource::MTIME);
		}

		return new ResolvedDate($this->uploadTime($file), DateSource::UPLOAD);
	}

	private function fromExif(?IFilesMetadata $metadata, \DateTimeZone $timezone): ?int {
		if ($metadata === null || !$metadata->hasKey(self::METADATA_KEY_EXIF)) {
			return null;
		}
		try {
			$exif = $metadata->getArray(self::METADATA_KEY_EXIF);
		} catch (\Throwable $e) {
			return null;
		}

		$raw = $exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? null;
		if (!is_string($raw) || $raw === '') {
			return null;
		}

		// EXIF writes "2024:07:12 14:03:25" with no zone attached, so one has to be
		// assumed. The user's is the right guess: a photo taken at 11pm belongs to
		// that day in the timezone the person who took it lives in, and reading it in
		// the server's zone instead is what pushes late-night photos into the wrong
		// month for anyone whose server is not where they are.
		return $this->parseStrict('Y:m:d H:i:s', $raw, $timezone);
	}

	private function fromMetadataTimestamp(?IFilesMetadata $metadata): ?int {
		if ($metadata === null || !$metadata->hasKey(self::METADATA_KEY_ORIGINAL_DATE_TIME)) {
			return null;
		}
		try {
			$timestamp = $metadata->getInt(self::METADATA_KEY_ORIGINAL_DATE_TIME);
		} catch (\Throwable $e) {
			return null;
		}
		return $this->isPlausible($timestamp) ? $timestamp : null;
	}

	/**
	 * Pulls a date out of a filename, or returns null if there is not a credible one.
	 */
	public function fromFilename(string $name, \DateTimeZone $timezone): ?int {
		foreach (self::FILENAME_PATTERNS as $pattern => $format) {
			$matches = [];
			if (preg_match($pattern, $name, $matches) !== 1) {
				continue;
			}

			// Separators inside a group vary between exporters, so both the captured
			// text and the format are flattened before parsing rather than writing a
			// pattern per punctuation style.
			$value = implode(' ', array_slice($matches, 1));
			$normalisedValue = str_replace(['_', '.', '-'], '', $value);
			$normalisedFormat = str_replace(['_', '.', '-', '?'], '', $format);

			// A date with no zone in it gets the same treatment as EXIF, and for the
			// same reason.
			$timestamp = $this->parseStrict('!' . $normalisedFormat, $normalisedValue, $timezone);
			if ($timestamp !== null) {
				return $timestamp;
			}
		}
		return null;
	}

	/**
	 * Parses a date and refuses anything that only *looks* like one.
	 *
	 * PHP's date parser is forgiving in a way that is actively harmful here: given
	 * "20241312" it does not fail, it rolls month thirteen over into January 2025. A
	 * serial number would then quietly create a month in the grid that holds one photo
	 * and that the user cannot account for. Formatting the result back and requiring
	 * it to match what was read rejects every such rollover.
	 */
	private function parseStrict(string $format, string $value, \DateTimeZone $timezone): ?int {
		$parsed = \DateTime::createFromFormat($format, $value, $timezone);
		if ($parsed === false) {
			return null;
		}

		if ($parsed->format(ltrim($format, '!')) !== $value) {
			return null;
		}

		$timestamp = $parsed->getTimestamp();
		return $this->isPlausible($timestamp) ? $timestamp : null;
	}

	private function uploadTime(File $file): int {
		try {
			$uploaded = $file->getUploadTime();
			if ($uploaded > 0) {
				return $uploaded;
			}
		} catch (\Throwable $e) {
			// Not every storage reports one.
		}
		return max($file->getMTime(), 0);
	}

	private function isPlausible(int $timestamp): bool {
		return $timestamp >= self::EARLIEST_PLAUSIBLE
			&& $timestamp <= (time() + self::FUTURE_TOLERANCE);
	}
}
