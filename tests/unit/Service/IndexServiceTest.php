<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoSweep\Tests\unit\Service;

use OCA\PhotoSweep\Service\IndexService;
use PHPUnit\Framework\TestCase;

class IndexServiceTest extends TestCase {

	/**
	 * @dataProvider boundaries
	 */
	public function testBucketsATimestampInTheGivenZone(int $timestamp, string $zone, string $expected): void {
		self::assertSame($expected, IndexService::yearMonth($timestamp, new \DateTimeZone($zone)));
	}

	public function boundaries(): array {
		return [
			'plain' => [1720789405, 'UTC', '2024-07'],
			// 2024-08-01 00:30 UTC is still 31 July in New York. Bucketing in UTC
			// would file it under August while the rest of the app calls it July.
			'just after midnight UTC' => [1722472200, 'America/New_York', '2024-07'],
			'same instant in UTC' => [1722472200, 'UTC', '2024-08'],
			// And the reverse: 11pm on 31 July in UTC is already August in Auckland.
			'late evening UTC, seen from Auckland' => [1722466800, 'Pacific/Auckland', '2024-08'],
			'same instant in UTC again' => [1722466800, 'UTC', '2024-07'],
		];
	}
}
