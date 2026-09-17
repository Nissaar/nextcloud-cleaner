<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoSweep\Service;

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
 * before slicing it, so it is slower on a large library, but it keeps working and
 * returns the same pages in the same order.
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
	 * One page of media, ordered by file id ascending.
	 *
	 * Paged by offset rather than by a "file id greater than" cursor, which would be
	 * the sturdier choice: Nextcloud's file search only accepts `eq` and `in` on
	 * `fileid`, so a keyset cursor is not expressible through it at all. Ordering by
	 * file id still makes the offset about as stable as an offset can be — new uploads
	 * take higher ids and land past the window rather than shifting it — and a scan is
	 * finished off by a sweep that drops anything the pass did not touch, so the
	 * remaining risk is that a file deleted mid-scan lets one other file slip past
	 * this pass. The next complete pass picks it up.
	 *
	 * @return File[]
	 */
	public function findBatch(Folder $scope, int $offset, int $limit): array {
		if (class_exists(SearchQuery::class)) {
			try {
				return $this->searchPaged($scope, $offset, $limit);
			} catch (\Throwable $e) {
				$this->logger->warning('Paged media search failed, falling back to searchByMime', [
					'exception' => $e,
					'app' => 'photosweep',
				]);
			}
		}
		return $this->searchByMimeFallback($scope, $offset, $limit);
	}

	/**
	 * @return File[]
	 */
	private function searchPaged(Folder $scope, int $offset, int $limit): array {
		$mimeComparisons = [];
		foreach (self::MIME_PREFIXES as $prefix) {
			$mimeComparisons[] = new SearchComparison(
				ISearchComparison::COMPARE_LIKE,
				'mimetype',
				$prefix . '/%',
			);
		}

		$query = new SearchQuery(
			new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, $mimeComparisons),
			$limit,
			$offset,
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
	private function searchByMimeFallback(Folder $scope, int $offset, int $limit): array {
		$nodes = [];
		foreach (self::MIME_PREFIXES as $prefix) {
			foreach ($scope->searchByMime($prefix) as $node) {
				$nodes[] = $node;
			}
		}

		$files = $this->onlyFiles($nodes);
		// Same order as the paged path, so a fallback mid-scan does not reshuffle
		// the window and skip whatever the previous page had already passed.
		usort($files, static fn (File $a, File $b): int => $a->getId() <=> $b->getId());

		return array_slice($files, $offset, $limit);
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
