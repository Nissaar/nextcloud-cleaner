<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudCleaner\BackgroundJob;

use OCA\NextcloudCleaner\Db\ScanMapper;
use OCA\NextcloudCleaner\Service\IndexService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Keeps each user's month index current.
 *
 * Only users who have opened the app have a scan row, and only those are indexed —
 * a photo library is expensive to walk and there is no reason to walk one for someone
 * who has never used the feature.
 */
class IndexJob extends TimedJob {

	/** How often the job is willing to run at all. */
	private const INTERVAL = 15 * 60;

	/** How stale a finished index may get before it is refreshed. */
	private const REFRESH_AFTER = 6 * 3600;

	/** Users per run, so one cron tick stays bounded no matter how many use the app. */
	private const USERS_PER_RUN = 5;

	public function __construct(
		ITimeFactory $time,
		private ScanMapper $scanMapper,
		private IndexService $indexService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$now = $this->time->getTime();
		$due = $this->scanMapper->findNeedingWork($now - self::REFRESH_AFTER, self::USERS_PER_RUN);

		foreach ($due as $scan) {
			try {
				$this->indexService->scan($scan->getUserId());
			} catch (\Throwable $e) {
				// One broken account must not stop the others being indexed.
				$this->logger->error('Nextcloud Cleaner background index failed', [
					'exception' => $e,
					'userId' => $scan->getUserId(),
					'app' => 'nextcloud_cleaner',
				]);
			}
		}
	}
}
