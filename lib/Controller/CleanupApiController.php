<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudCleaner\Controller;

use OCA\NextcloudCleaner\Service\CleanupService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The two endpoints that change files.
 *
 * Both are POST and neither is exempt from CSRF checks, which is deliberate: these
 * are the only calls in the app that cannot be undone by pressing something else.
 */
class CleanupApiController extends AuthenticatedOcsController {

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		private CleanupService $cleanupService,
	) {
		parent::__construct($appName, $request, $userSession);
	}

	/**
	 * Carries out every pending delete verdict, in whichever mode is configured.
	 *
	 * The client is expected to have shown the user the full list first. This endpoint
	 * does not confirm anything on its own.
	 */
	#[NoAdminRequired]
	public function apply(): DataResponse {
		return new DataResponse($this->cleanupService->apply($this->userId()));
	}

	/**
	 * Puts already-applied items back: out of the trash, or back out of the folder.
	 *
	 * @param int[] $fileIds
	 */
	#[NoAdminRequired]
	public function restore(array $fileIds): DataResponse {
		if ($fileIds === []) {
			throw new OCSBadRequestException('No files given');
		}

		$ids = array_values(array_filter(array_map('intval', $fileIds), static fn (int $id): bool => $id > 0));
		if ($ids === []) {
			throw new OCSBadRequestException('No valid file ids given');
		}

		return new DataResponse($this->cleanupService->restore($this->userId(), $ids));
	}
}
