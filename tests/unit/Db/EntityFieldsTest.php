<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Tests\unit\Db;

use OCA\PhotoCleaner\Db\DateSource;
use OCA\PhotoCleaner\Db\Decision;
use OCA\PhotoCleaner\Db\Media;
use OCA\PhotoCleaner\Db\Scan;
use OCA\PhotoCleaner\Db\Verdict;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Guards against a trap in Nextcloud's Entity that is invisible until the database
 * rejects a row.
 *
 * `Entity::setter()` returns early when the value being set already equals the
 * property's current value, and that early return means the field is never marked
 * dirty — so `QBMapper::insert()` omits the column from the INSERT and the row is
 * written with a null. It cost a real bug: with `protected $verdict = 'keep'`,
 * recording a *keep* produced a null verdict and a constraint violation, while
 * recording a *delete* worked perfectly, so half the app looked fine.
 *
 * The rule these tests enforce is simply that no property starts life holding a value
 * a caller might legitimately set.
 */
class EntityFieldsTest extends TestCase {

	/**
	 * @dataProvider entities
	 */
	public function testNoPropertyStartsWithAUsableValue(Entity $entity): void {
		$reflection = new \ReflectionClass($entity);
		$fresh = $reflection->newInstance();

		foreach ($reflection->getProperties(\ReflectionProperty::IS_PROTECTED) as $property) {
			if (str_starts_with($property->getName(), '_')) {
				continue; // Entity's own bookkeeping.
			}
			$property->setAccessible(true);
			self::assertNull(
				$property->getValue($fresh),
				$reflection->getShortName() . '::$' . $property->getName()
					. ' starts with a value, so setting that same value would be dropped from the INSERT',
			);
		}
	}

	public function entities(): array {
		return [
			'media' => [new Media()],
			'decision' => [new Decision()],
			'scan' => [new Scan()],
		];
	}

	public function testRecordingAKeepMarksTheVerdictColumn(): void {
		$decision = new Decision();
		$decision->setVerdict(Verdict::KEEP);

		// The exact case that failed: a keep is the first verdict most people give.
		self::assertArrayHasKey('verdict', $decision->getUpdatedFields());
	}

	public function testRecordingADeleteMarksTheVerdictColumn(): void {
		$decision = new Decision();
		$decision->setVerdict(Verdict::DELETE);

		self::assertArrayHasKey('verdict', $decision->getUpdatedFields());
	}

	public function testFalseAndZeroAreStillWritten(): void {
		$decision = new Decision();
		$decision->setApplied(false);
		$decision->setSize(0);
		$decision->setTakenAt(0);

		$updated = $decision->getUpdatedFields();
		// A false or a zero is a real value, and the same early return would drop it.
		self::assertArrayHasKey('applied', $updated);
		self::assertArrayHasKey('size', $updated);
		self::assertArrayHasKey('takenAt', $updated);
	}

	public function testTheDefaultDateSourceIsStillWritten(): void {
		$media = new Media();
		$media->setDateSource(DateSource::MTIME);

		// Most files in a typical library resolve to mtime, so this is the common
		// path rather than an edge case.
		self::assertArrayHasKey('dateSource', $media->getUpdatedFields());
	}

	public function testAFreshScanWritesEveryStartingValue(): void {
		$scan = new Scan();
		$scan->setUserId('alice');
		$scan->setCursorOffset(0);
		$scan->setComplete(false);
		$scan->setRunning(false);
		$scan->setFound(0);
		$scan->setStartedAt(0);
		$scan->setUpdatedAt(0);

		$updated = $scan->getUpdatedFields();
		foreach (['userId', 'cursorOffset', 'complete', 'running', 'found', 'startedAt', 'updatedAt'] as $field) {
			self::assertArrayHasKey($field, $updated, $field . ' would be left out of the INSERT');
		}
	}
}
