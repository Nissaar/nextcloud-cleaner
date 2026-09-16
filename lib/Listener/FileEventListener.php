<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Listener;

use OCA\PhotoCleaner\Db\MediaMapper;
use OCA\PhotoCleaner\Db\ScanMapper;
use OCA\PhotoCleaner\Service\MediaFinder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Keeps the index in step with the filesystem between scans.
 *
 * Only deletions are handled eagerly, and only for users who have a scan row — that
 * is, people who actually use the app. A photo that has been deleted but is still
 * offered up for review is the single most confusing thing this app could do, so it
 * is worth reacting to immediately rather than waiting for the next pass.
 *
 * Additions are deliberately left to the background job: a file that has just landed
 * usually has no extracted EXIF yet, so indexing it this instant would date it from
 * its filename or mtime and quite possibly file it under the wrong month.
 *
 * @template-implements IEventListener<NodeDeletedEvent>
 */
class FileEventListener implements IEventListener {

	public function __construct(
		private MediaMapper $mediaMapper,
		private ScanMapper $scanMapper,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof NodeDeletedEvent) {
			return;
		}

		$node = $event->getNode();
		if (!$node instanceof File) {
			return;
		}

		try {
			if (!MediaFinder::isMedia($node->getMimeType())) {
				return;
			}
			$userId = $this->ownerOf($node);
			if ($userId === null || $this->scanMapper->find($userId) === null) {
				return;
			}
			$this->mediaMapper->removeByFileId($userId, $node->getId());
		} catch (\Throwable $e) {
			// The index going briefly out of date is survivable; blocking a file
			// deletion because of it is not.
			$this->logger->debug('Could not update the index for a deleted file', [
				'exception' => $e,
				'app' => 'photocleaner',
			]);
		}
	}

	private function ownerOf(Node $node): ?string {
		$owner = $node->getOwner();
		return $owner === null ? null : $owner->getUID();
	}
}
