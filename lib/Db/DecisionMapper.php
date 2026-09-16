<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Decision>
 */
class DecisionMapper extends QBMapper {
	public const TABLE = 'photocleaner_decisions';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Decision::class);
	}

	/** Re-deciding a photo replaces the old verdict rather than adding a second one. */
	public function upsert(Decision $decision): Decision {
		try {
			$existing = $this->findByFileId($decision->getUserId(), $decision->getFileId());
			$decision->setId($existing->getId());
			return $this->update($decision);
		} catch (DoesNotExistException $e) {
			return $this->insert($decision);
		}
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByFileId(string $userId, int $fileId): Decision {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Verdicts that have not been carried out yet — the review screen's contents.
	 *
	 * @return Decision[]
	 */
	public function findPending(string $userId, string $verdict = Verdict::DELETE): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('verdict', $qb->createNamedParameter($verdict)))
			->andWhere($qb->expr()->eq('applied', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('decided_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Verdicts already carried out — the source list for undo.
	 *
	 * @return Decision[]
	 */
	public function findApplied(string $userId, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('verdict', $qb->createNamedParameter(Verdict::DELETE)))
			->andWhere($qb->expr()->eq('applied', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('applied_at', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * @param int[] $fileIds
	 * @return Decision[]
	 */
	public function findByFileIds(string $userId, array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}
		$found = [];
		foreach (array_chunk($fileIds, 900) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from(self::TABLE)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->findEntities($qb) as $entity) {
				$found[] = $entity;
			}
		}
		return $found;
	}

	public function countPending(string $userId, string $verdict = Verdict::DELETE): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('verdict', $qb->createNamedParameter($verdict)))
			->andWhere($qb->expr()->eq('applied', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();
		return $total;
	}

	public function countByVerdict(string $userId, string $verdict): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('verdict', $qb->createNamedParameter($verdict)));
		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();
		return $total;
	}

	/**
	 * How many items have been judged in each month, for the grid's progress bars.
	 *
	 * @return array<string, int>
	 */
	public function decidedCountsByMonth(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('year_month')
			->selectAlias($qb->func()->count('*'), 'total')
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->groupBy('year_month');

		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(string)$row['year_month']] = (int)$row['total'];
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * File ids already judged in one month, so reopening it can skip them.
	 *
	 * @return int[]
	 */
	public function decidedFileIdsForMonth(string $userId, string $yearMonth): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id')
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($yearMonth)));

		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['file_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * Forgets one month's verdicts so it can be reviewed again.
	 *
	 * Spares applied rows on purpose: those files have already been moved or trashed,
	 * they are no longer in the index to review, and their rows are the undo history.
	 *
	 * @return int how many verdicts were forgotten
	 */
	public function clearUnappliedForMonth(string $userId, string $yearMonth): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('year_month', $qb->createNamedParameter($yearMonth)))
			->andWhere($qb->expr()->eq('applied', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
		return $qb->executeStatement();
	}

	public function removeByFileId(string $userId, int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @param int[] $fileIds
	 */
	public function removeByFileIds(string $userId, array $fileIds): void {
		if ($fileIds === []) {
			return;
		}
		foreach (array_chunk($fileIds, 900) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	/**
	 * Marks verdicts as carried out. Called only after the files have actually moved,
	 * so an interrupted run leaves rows pending — safe to retry — rather than claiming
	 * work it did not do.
	 *
	 * @param int[] $fileIds
	 */
	public function markApplied(string $userId, array $fileIds, int $at, string $mode): void {
		if ($fileIds === []) {
			return;
		}
		foreach (array_chunk($fileIds, 900) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('applied', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->set('applied_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT))
				->set('applied_mode', $qb->createNamedParameter($mode))
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
	}

	public function removeAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
