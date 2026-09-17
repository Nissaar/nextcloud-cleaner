<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudCleaner\Controller;

use OCA\NextcloudCleaner\Service\ConfigService;
use OCA\NextcloudCleaner\Service\IndexService;
use OCA\NextcloudCleaner\Service\TimelineService;
use OCA\NextcloudCleaner\Service\TrashService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The month grid, the deck for one month, and the state of the index behind them.
 */
class TimelineApiController extends AuthenticatedOcsController {

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		private TimelineService $timelineService,
		private IndexService $indexService,
		private ConfigService $configService,
		private TrashService $trashService,
	) {
		parent::__construct($appName, $request, $userSession);
	}

	/**
	 * Where the index has got to, plus the headline numbers.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(): DataResponse {
		$userId = $this->userId();

		return new DataResponse([
			'scan' => $this->indexService->getStatus($userId),
			'summary' => $this->timelineService->summary($userId),
			'config' => $this->configService->asArray($userId),
			// The client shows this next to the trash option, so nobody chooses an
			// irreversible delete believing it is reversible.
			'trashAvailable' => $this->trashService->isAvailable(),
		]);
	}

	/**
	 * Advances the index by one bounded chunk and reports where it got to.
	 *
	 * Bounded rather than run-to-completion because this is a web request: the client
	 * calls it again while `complete` is false, which is what turns a five-minute
	 * first scan into visible progress instead of a timeout.
	 */
	#[NoAdminRequired]
	public function scan(bool $full = false): DataResponse {
		$userId = $this->userId();
		$scan = $this->indexService->scan($userId, $full, null, IndexService::WEB_BATCHES);

		return new DataResponse([
			'scan' => $scan,
			'summary' => $this->timelineService->summary($userId),
		]);
	}

	/**
	 * Every month that holds photos, newest first.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function months(): DataResponse {
		$userId = $this->userId();

		return new DataResponse([
			'months' => $this->timelineService->months($userId),
			'summary' => $this->timelineService->summary($userId),
		]);
	}

	/**
	 * One month's photos, newest first.
	 *
	 * @param string $month e.g. "2024-07"
	 * @param bool|null $skipDecided override the user's setting for this call
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function month(string $month, ?bool $skipDecided = null): DataResponse {
		$userId = $this->userId();
		try {
			$items = $this->timelineService->monthItems($userId, $month, $skipDecided);
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}

		return new DataResponse([
			'month' => $month,
			'items' => $items,
		]);
	}

	/**
	 * Forgets a month's verdicts so it can be gone through again.
	 *
	 * Touches nothing on disk. Photos already trashed or moved stay that way.
	 */
	#[NoAdminRequired]
	public function resetMonth(string $month): DataResponse {
		$userId = $this->userId();
		try {
			$cleared = $this->timelineService->resetMonth($userId, $month);
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}

		return new DataResponse(['cleared' => $cleared], Http::STATUS_OK);
	}
}
