<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoSweep\Controller;

use OCA\PhotoSweep\AppInfo\Application;
use OCA\PhotoSweep\Service\ConfigService;
use OCA\PhotoSweep\Service\TrashService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IInitialState $initialState,
		private IUserSession $userSession,
		private ConfigService $configService,
		private TrashService $trashService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			// Handed over with the page so the first screen can render without waiting
			// on a round trip just to learn which delete mode is selected.
			$this->initialState->provideInitialState('config', $this->configService->asArray($user->getUID()));
			$this->initialState->provideInitialState('trashAvailable', $this->trashService->isAvailable());
		}

		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');

		$response = new TemplateResponse(Application::APP_ID, 'main');

		// Videos are played straight from the Files DAV endpoint on this same origin,
		// and previews come from core. Nothing external is ever loaded, so the default
		// policy is left as strict as it comes.
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedMediaDomain("'self'");
		$response->setContentSecurityPolicy($policy);

		return $response;
	}
}
