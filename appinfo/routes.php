<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The web UI is a single page that keeps its own view state, so there is exactly one
 * HTML route. Everything else is OCS, which is what lets the Android client reach the
 * same endpoints with an app password instead of a session cookie.
 */
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
	],
	'ocs' => [
		// Index and timeline
		['name' => 'timelineApi#status', 'url' => '/api/v1/index', 'verb' => 'GET'],
		['name' => 'timelineApi#scan', 'url' => '/api/v1/index', 'verb' => 'POST'],
		['name' => 'timelineApi#months', 'url' => '/api/v1/months', 'verb' => 'GET'],
		['name' => 'timelineApi#month', 'url' => '/api/v1/months/{month}', 'verb' => 'GET'],
		['name' => 'timelineApi#resetMonth', 'url' => '/api/v1/months/{month}', 'verb' => 'DELETE'],

		// Verdicts, held locally until applied
		['name' => 'decisionApi#pending', 'url' => '/api/v1/decisions/pending', 'verb' => 'GET'],
		['name' => 'decisionApi#applied', 'url' => '/api/v1/decisions/applied', 'verb' => 'GET'],
		['name' => 'decisionApi#record', 'url' => '/api/v1/decisions', 'verb' => 'POST'],
		['name' => 'decisionApi#undo', 'url' => '/api/v1/decisions/{fileId}', 'verb' => 'DELETE'],

		// The only endpoints that change files
		['name' => 'cleanupApi#apply', 'url' => '/api/v1/apply', 'verb' => 'POST'],
		['name' => 'cleanupApi#restore', 'url' => '/api/v1/restore', 'verb' => 'POST'],

		// Per-user settings
		['name' => 'configApi#show', 'url' => '/api/v1/config', 'verb' => 'GET'],
		['name' => 'configApi#update', 'url' => '/api/v1/config', 'verb' => 'PUT'],
	],
];
