<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudCleaner\Tests\unit\Service;

use OCA\NextcloudCleaner\Db\DecisionMapper;
use OCA\NextcloudCleaner\Db\Media;
use OCA\NextcloudCleaner\Db\MediaMapper;
use OCA\NextcloudCleaner\Service\ConfigService;
use OCA\NextcloudCleaner\Service\TimelineService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TimelineServiceTest extends TestCase {

	private MediaMapper&MockObject $mediaMapper;
	private DecisionMapper&MockObject $decisionMapper;
	private ConfigService&MockObject $configService;
	private TimelineService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->mediaMapper = $this->createMock(MediaMapper::class);
		$this->decisionMapper = $this->createMock(DecisionMapper::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->service = new TimelineService(
			$this->mediaMapper,
			$this->decisionMapper,
			$this->configService,
		);
	}

	/**
	 * @dataProvider months
	 */
	public function testRecognisesValidMonths(string $value, bool $valid): void {
		self::assertSame($valid, TimelineService::isValidMonth($value));
	}

	public function months(): array {
		return [
			['2024-07', true],
			['2024-01', true],
			['2024-12', true],
			['2024-00', false],
			['2024-13', false],
			['2024-7', false],
			['24-07', false],
			['not-a-month', false],
			// The month reaches the database through a query parameter, so anything
			// that is not exactly this shape is refused before it gets that far.
			["2024-07' OR '1'='1", false],
		];
	}

	public function testReportsProgressPerMonth(): void {
		$this->mediaMapper->method('monthCounts')->willReturn([
			'2024-07' => 40,
			'2024-06' => 12,
			'2024-05' => 0,
		]);
		$this->decisionMapper->method('decidedCountsByMonth')->willReturn([
			'2024-07' => 10,
			'2024-06' => 12,
		]);

		$months = $this->service->months('alice');

		self::assertSame(
			[
				['month' => '2024-07', 'total' => 40, 'reviewed' => 10, 'remaining' => 30, 'done' => false],
				['month' => '2024-06', 'total' => 12, 'reviewed' => 12, 'remaining' => 0, 'done' => true],
				['month' => '2024-05', 'total' => 0, 'reviewed' => 0, 'remaining' => 0, 'done' => false],
			],
			$months,
		);
	}

	public function testReviewedNeverExceedsTheTotal(): void {
		// Applied verdicts stay counted after their files leave the index, so the raw
		// tally can legitimately run past what is left. Reporting "40 of 12 reviewed"
		// would be worse than useless.
		$this->mediaMapper->method('monthCounts')->willReturn(['2024-07' => 12]);
		$this->decisionMapper->method('decidedCountsByMonth')->willReturn(['2024-07' => 40]);

		$months = $this->service->months('alice');

		self::assertSame(12, $months[0]['reviewed']);
		self::assertSame(0, $months[0]['remaining']);
		self::assertTrue($months[0]['done']);
	}

	public function testHidesItemsThatAlreadyHaveAVerdict(): void {
		$this->mediaMapper->method('findForMonth')->willReturn([
			$this->media(1),
			$this->media(2),
			$this->media(3),
		]);
		$this->decisionMapper->method('decidedFileIdsForMonth')->willReturn([2]);

		$items = $this->service->monthItems('alice', '2024-07', true);

		self::assertSame([1, 3], array_map(static fn (Media $m): int => $m->getFileId(), $items));
	}

	public function testCanBeAskedForEverythingIncludingDecidedItems(): void {
		$this->mediaMapper->method('findForMonth')->willReturn([
			$this->media(1),
			$this->media(2),
		]);
		$this->decisionMapper->expects(self::never())->method('decidedFileIdsForMonth');

		$items = $this->service->monthItems('alice', '2024-07', false);

		self::assertCount(2, $items);
	}

	public function testRefusesAMalformedMonth(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->monthItems('alice', '2024-13');
	}

	public function testRefusesToResetAMalformedMonth(): void {
		$this->decisionMapper->expects(self::never())->method('clearUnappliedForMonth');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->resetMonth('alice', 'whenever');
	}

	private function media(int $fileId): Media {
		$media = new Media();
		$media->setFileId($fileId);
		$media->setYearMonth('2024-07');
		return $media;
	}
}
