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
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Records what you decided about each photo, and nothing more.
 *
 * Writing a verdict never touches the file. That separation is the whole safety model:
 * you can go through a thousand photos, change your mind about any of them, and walk
 * away without having changed anything at all.
 */
class DecisionService {

	public function __construct(
		private DecisionMapper $decisionMapper,
		private MediaMapper $mediaMapper,
	) {
	}

	/**
	 * @throws DoesNotExistException if the file is not in the index
	 * @throws \InvalidArgumentException on an unknown verdict
	 */
	public function record(string $userId, int $fileId, string $verdict): Decision {
		if (!Verdict::isValid($verdict)) {
			throw new \InvalidArgumentException('Unknown verdict: ' . $verdict);
		}

		$media = $this->mediaMapper->findByFileId($userId, $fileId);

		$decision = new Decision();
		$decision->setUserId($userId);
		$decision->setFileId($fileId);
		$decision->setVerdict($verdict);
		$decision->setDecidedAt(time());
		$decision->setApplied(false);
		$decision->setTakenAt($media->getTakenAt());
		$decision->setYearMonth($media->getYearMonth());
		$decision->setName($media->getName());
		$decision->setSize($media->getSize());
		$decision->setIsVideo($media->getIsVideo());
		// Captured now, while the file is still where it belongs. After it moves there
		// is nothing left to read the original location from, and an undo needs it.
		$decision->setOriginPath($media->getPath());

		return $this->decisionMapper->upsert($decision);
	}

	/**
	 * Takes a verdict back, before it has been carried out.
	 *
	 * Refuses to touch an applied one: that row is history, and the way back from
	 * there is a restore, not an undo.
	 *
	 * @return bool whether there was anything to undo
	 */
	public function undo(string $userId, int $fileId): bool {
		try {
			$decision = $this->decisionMapper->findByFileId($userId, $fileId);
		} catch (DoesNotExistException $e) {
			return false;
		}

		if ($decision->getApplied()) {
			return false;
		}

		$this->decisionMapper->delete($decision);
		return true;
	}

	/**
	 * @param array<array{fileId: int, verdict: string}> $verdicts
	 * @return array{recorded: int, skipped: int[]}
	 */
	public function recordMany(string $userId, array $verdicts): array {
		$recorded = 0;
		$skipped = [];
		foreach ($verdicts as $entry) {
			$fileId = (int)($entry['fileId'] ?? 0);
			$verdict = (string)($entry['verdict'] ?? '');
			try {
				$this->record($userId, $fileId, $verdict);
				$recorded++;
			} catch (\Throwable $e) {
				// A file that left the library between the deck loading and the
				// phone coming back online is expected, not an error.
				$skipped[] = $fileId;
			}
		}
		return ['recorded' => $recorded, 'skipped' => $skipped];
	}

	/**
	 * @return Decision[]
	 */
	public function pending(string $userId): array {
		return $this->decisionMapper->findPending($userId, Verdict::DELETE);
	}

	/**
	 * @return Decision[]
	 */
	public function applied(string $userId): array {
		return $this->decisionMapper->findApplied($userId);
	}
}
