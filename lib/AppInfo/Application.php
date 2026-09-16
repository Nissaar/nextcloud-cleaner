<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\AppInfo;

use OCA\PhotoCleaner\Listener\FileEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'photocleaner';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_ID, $params);
	}

	public function register(IRegistrationContext $context): void {
		// A deleted photo must stop being offered for review straight away. New files
		// are picked up by the background job instead, once their EXIF has been read.
		$context->registerEventListener(NodeDeletedEvent::class, FileEventListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
