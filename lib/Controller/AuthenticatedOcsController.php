<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudCleaner\Controller;

use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Shared base for the API controllers.
 *
 * The current user is resolved from the session rather than taken from a request
 * parameter, so there is no route on which one account can name another. Every query
 * below it is scoped by the id this returns.
 */
abstract class AuthenticatedOcsController extends OCSController {

	public function __construct(
		string $appName,
		IRequest $request,
		protected IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @throws OCSForbiddenException
	 */
	protected function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Not signed in');
		}
		return $user->getUID();
	}
}
