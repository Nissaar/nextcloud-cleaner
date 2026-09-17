<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudCleaner\Tests\unit\Service;

use OCA\NextcloudCleaner\Db\DateSource;
use OCA\NextcloudCleaner\Service\DateResolver;
use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The date chain is where a photo ends up in the wrong month, so it gets the tests.
 */
class DateResolverTest extends TestCase {

	private DateResolver $resolver;
	private \DateTimeZone $utc;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new DateResolver(
			$this->createMock(IFilesMetadataManager::class),
			new NullLogger(),
		);
		$this->utc = new \DateTimeZone('UTC');
	}

	/**
	 * @dataProvider filenamesWithDates
	 */
	public function testReadsTheDateOutOfAFilename(string $name, string $expectedDate): void {
		$timestamp = $this->resolver->fromFilename($name, $this->utc);

		self::assertNotNull($timestamp, $name . ' should yield a date');
		self::assertSame(
			$expectedDate,
			(new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->utc)->format('Y-m-d'),
			$name,
		);
	}

	public function filenamesWithDates(): array {
		return [
			'android camera' => ['IMG_20240712_140325.jpg', '2024-07-12'],
			'android video' => ['VID_20240712_140325.mp4', '2024-07-12'],
			// Pixel appends milliseconds, which used to defeat the time pattern and
			// fall through to the date-only one. Either way the month must be right.
			'pixel with milliseconds' => ['PXL_20240712_140325123.MP.jpg', '2024-07-12'],
			'screenshot' => ['Screenshot_20240712-140325.png', '2024-07-12'],
			'dashed with time' => ['2024-07-12-14-03-25.jpg', '2024-07-12'],
			'dotted time' => ['2024-07-12 14.03.25.jpg', '2024-07-12'],
			'date only' => ['holiday 2024-07-12.jpg', '2024-07-12'],
			'compact date only' => ['scan20240712.tif', '2024-07-12'],
			// The time here is nonsense and is rejected, but the date beside it is
			// not. Degrading to the right day beats discarding a usable month.
			'garbled time, usable date' => ['IMG_20240712_259999.jpg', '2024-07-12'],
		];
	}

	/**
	 * @dataProvider filenamesWithoutDates
	 */
	public function testRejectsThingsThatAreNotDates(string $name): void {
		self::assertNull($this->resolver->fromFilename($name, $this->utc), $name);
	}

	public function filenamesWithoutDates(): array {
		return [
			'plain name' => ['beach.jpg'],
			// An eight-digit serial parses happily into the year 8402 if nothing
			// stops it, and one bad parse invents a month the user cannot get rid of.
			'serial number' => ['84021599.jpg'],
			'short number' => ['IMG_1234.jpg'],
			// PHP's date parser rolls these over rather than rejecting them: month
			// thirteen becomes next January, 30 February becomes 1 March. Both would
			// invent a month in the grid out of what is really a serial number.
			'impossible month' => ['20241312.jpg'],
			'impossible day' => ['20240230.jpg'],
			'too old to be a photo' => ['19010101.jpg'],
		];
	}

	public function testFilenameDatesAreReadInTheGivenZone(): void {
		$auckland = new \DateTimeZone('Pacific/Auckland');

		$utcTimestamp = $this->resolver->fromFilename('IMG_20240712_000500.jpg', $this->utc);
		$aucklandTimestamp = $this->resolver->fromFilename('IMG_20240712_000500.jpg', $auckland);

		self::assertNotNull($utcTimestamp);
		self::assertNotNull($aucklandTimestamp);
		// Midnight-ish on the 12th is a different instant in each zone. Reading it in
		// the user's own zone is what keeps a late-night photo in the right month.
		self::assertNotSame($utcTimestamp, $aucklandTimestamp);
		self::assertSame(
			'2024-07-12',
			(new \DateTimeImmutable('@' . $aucklandTimestamp))->setTimezone($auckland)->format('Y-m-d'),
		);
	}

	public function testPrefersExifOverEverythingElse(): void {
		// A filename that says July, EXIF that says March: EXIF wins, because it came
		// from the camera and the filename came from whatever last renamed the file.
		$file = $this->file('IMG_20240712_140325.jpg', mtime: 1720000000);
		$metadata = $this->metadataWithExif('2024:03:04 09:10:11');

		$resolved = $this->resolver->resolve($file, $this->utc, [1 => $metadata]);

		self::assertSame(DateSource::EXIF, $resolved->source);
		self::assertSame(
			'2024-03-04',
			(new \DateTimeImmutable('@' . $resolved->timestamp))->setTimezone($this->utc)->format('Y-m-d'),
		);
	}

	public function testFallsBackToTheFilenameWhenThereIsNoExif(): void {
		$file = $this->file('IMG_20240712_140325.jpg', mtime: 1720000000);

		$resolved = $this->resolver->resolve($file, $this->utc, []);

		self::assertSame(DateSource::FILENAME, $resolved->source);
	}

	public function testFallsBackToMtimeWhenNothingElseIsAvailable(): void {
		$mtime = 1720789405;
		$file = $this->file('beach.jpg', mtime: $mtime);

		$resolved = $this->resolver->resolve($file, $this->utc, []);

		self::assertSame(DateSource::MTIME, $resolved->source);
		self::assertSame($mtime, $resolved->timestamp);
	}

	public function testIgnoresAnUnusableExifDate(): void {
		$file = $this->file('beach.jpg', mtime: 1720789405);
		$metadata = $this->metadataWithExif('not a date at all');

		$resolved = $this->resolver->resolve($file, $this->utc, [1 => $metadata]);

		self::assertSame(DateSource::MTIME, $resolved->source);
	}

	public function testUsesUploadTimeWhenTheMtimeIsImplausible(): void {
		$uploaded = 1720789405;
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(1);
		$file->method('getName')->willReturn('beach.jpg');
		// Storages that lose metadata report a zero mtime, which would otherwise file
		// the photo under January 1970.
		$file->method('getMTime')->willReturn(0);
		$file->method('getUploadTime')->willReturn($uploaded);

		$resolved = $this->resolver->resolve($file, $this->utc, []);

		self::assertSame(DateSource::UPLOAD, $resolved->source);
		self::assertSame($uploaded, $resolved->timestamp);
	}

	private function file(string $name, int $mtime): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(1);
		$file->method('getName')->willReturn($name);
		$file->method('getMTime')->willReturn($mtime);
		$file->method('getUploadTime')->willReturn($mtime);
		return $file;
	}

	private function metadataWithExif(string $dateTimeOriginal): IFilesMetadata {
		$metadata = $this->createMock(IFilesMetadata::class);
		$metadata->method('hasKey')
			->willReturnCallback(static fn (string $key): bool => $key === DateResolver::METADATA_KEY_EXIF);
		$metadata->method('getArray')->willReturn(['DateTimeOriginal' => $dateTimeOriginal]);
		return $metadata;
	}
}
