<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudCleaner\Controller;

use OCA\NextcloudCleaner\Service\DecisionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Verdicts. None of these endpoints touches a file.
 */
class DecisionApiController extends AuthenticatedOcsController {

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		private DecisionService $decisionService,
	) {
		parent::__construct($appName, $request, $userSession);
	}

	/**
	 * Records a verdict, or a batch of them.
	 *
	 * The batch form exists for the phone: a review session on a train produces a
	 * hundred verdicts with no connection, and they should reach the server in one
	 * request when it comes back rather than a hundred.
	 *
	 * @param int|null $fileId single verdict
	 * @param string|null $verdict "keep" or "delete"
	 * @param array<array{fileId: int, verdict: string}>|null $verdicts batch form
	 */
	#[NoAdminRequired]
	public function record(?int $fileId = null, ?string $verdict = null, ?array $verdicts = null): DataResponse {
		$userId = $this->userId();

		if ($verdicts !== null) {
			return new DataResponse($this->decisionService->recordMany($userId, $verdicts));
		}

		if ($fileId === null || $verdict === null) {
			throw new OCSBadRequestException('Provide either fileId and verdict, or a verdicts array');
		}

		try {
			$decision = $this->decisionService->record($userId, $fileId, $verdict);
		} catch (DoesNotExistException $e) {
			throw new OCSNotFoundException('That file is not in your index');
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}

		return new DataResponse($decision);
	}

	/**
	 * Takes a verdict back. Only works while it is still pending.
	 */
	#[NoAdminRequired]
	public function undo(int $fileId): DataResponse {
		$undone = $this->decisionService->undo($this->userId(), $fileId);
		if (!$undone) {
			throw new OCSNotFoundException('Nothing pending to undo for that file');
		}
		return new DataResponse(['undone' => true]);
	}

	/**
	 * Everything marked for deletion but not yet carried out — the review list.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pending(): DataResponse {
		return new DataResponse(['decisions' => $this->decisionService->pending($this->userId())]);
	}

	/**
	 * Everything already trashed or moved — the list an undo is chosen from.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function applied(): DataResponse {
		return new DataResponse(['decisions' => $this->decisionService->applied($this->userId())]);
	}
}
