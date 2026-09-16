<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Db;

final class Verdict {
	public const KEEP = 'keep';
	public const DELETE = 'delete';

	public static function isValid(string $verdict): bool {
		return $verdict === self::KEEP || $verdict === self::DELETE;
	}
}
