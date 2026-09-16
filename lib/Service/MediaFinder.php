<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Service;

use OC\Files\Search\SearchBinaryOperator;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchOrder;
use OC\Files\Search\SearchQuery;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOrder;
use Psr\Log\LoggerInterface;

/**
 * Finds photos and videos in a folder, a page at a time.
 *
 * Every dependency on Nextcloud's file search lives in this one class, on purpose.
 * Constructing an `ISearchQuery` needs the `OC\Files\Search` implementations — there
 * is no public factory for them — so the coupling is real and is better concentrated
 * in one small file than sprinkled through the indexer.
 *
 * If those classes ever move, {@see findBatch} degrades to the public
 * `Folder::searchByMime()` instead of breaking. That path loads the whole result set
 * rather than a page, so it is slower on a large library, but it keeps working.
 */
class MediaFinder {

	/**
	 * Mime prefixes that count as library media.
	 *
	 * Broader than the Photos app's own list, which excludes GIF and BMP as "too
	 * rarely used for photos". Here the point is to clean up everything, and a folder
	 * of old GIFs is exactly the kind of thing worth going through.
	 */
	private const MIME_PREFIXES = ['image', 'video'];

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * One page of media, ordered by file id ascending and starting after [$afterFileId].
	 *
	 * File id is the paging key rather than an offset because the index is built over
	 * many background runs: an offset drifts as files are added or removed underneath
	 * it, silently skipping or repeating items, while a file-id cursor cannot.
	 *
	 * @return File[]
	 */
	public function findBatch(Folder $scope, int $afterFileId, int $limit): array {
		if (class_exists(SearchQuery::class)) {
			try {
				return $this->searchPaged($scope, $afterFileId, $limit);
			} catch (\Throwable $e) {
				$this->logger->warning('Paged media search failed, falling back to searchByMime', [
					'exception' => $e,
					'app' => 'photocleaner',
				]);
			}
		}
		return $this->searchByMimeFallback($scope, $afterFileId, $limit);
	}

	/**
	 * @return File[]
	 */
	private function searchPaged(Folder $scope, int $afterFileId, int $limit): array {
		$mimeComparisons = [];
		foreach (self::MIME_PREFIXES as $prefix) {
			$mimeComparisons[] = new SearchComparison(
				ISearchComparison::COMPARE_LIKE,
				'mimetype',
				$prefix . '/%',
			);
		}

		$operator = new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_AND, [
			new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, $mimeComparisons),
			new SearchComparison(ISearchComparison::COMPARE_GREATER_THAN, 'fileid', $afterFileId),
		]);

		$query = new SearchQuery(
			$operator,
			$limit,
			0,
			[new SearchOrder(ISearchOrder::DIRECTION_ASCENDING, 'fileid')],
		);

		// The parameter is typed against the public ISearchQuery, which this private
		// class implements — psalm cannot see that from the OCP stubs alone.
		/** @psalm-suppress InvalidArgument */
		return $this->onlyFiles($scope->search($query));
	}

	/**
	 * Public-API fallback: fetch everything, then page in memory.
	 *
	 * @return File[]
	 */
	private function searchByMimeFallback(Folder $scope, int $afterFileId, int $limit): array {
		$nodes = [];
		foreach (self::MIME_PREFIXES as $prefix) {
			foreach ($scope->searchByMime($prefix) as $node) {
				$nodes[] = $node;
			}
		}

		$files = $this->onlyFiles($nodes);
		$files = array_values(array_filter($files, static fn (File $f): bool => $f->getId() > $afterFileId));
		usort($files, static fn (File $a, File $b): int => $a->getId() <=> $b->getId());

		return array_slice($files, 0, $limit);
	}

	/**
	 * @param Node[] $nodes
	 * @return File[]
	 */
	private function onlyFiles(array $nodes): array {
		$files = [];
		foreach ($nodes as $node) {
			if ($node instanceof File) {
				$files[] = $node;
			}
		}
		return $files;
	}

	public static function isMedia(string $mimetype): bool {
		foreach (self::MIME_PREFIXES as $prefix) {
			if (str_starts_with($mimetype, $prefix . '/')) {
				return true;
			}
		}
		return false;
	}
}
