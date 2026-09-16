<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Service;

/**
 * What came of carrying out a batch of verdicts.
 *
 * Failures are returned rather than thrown: a run where nine hundred files moved and
 * three did not is a success that needs a footnote, not an error.
 */
class ApplyResult implements \JsonSerializable {
	/**
	 * @param array<int, string> $failures file id => why it failed
	 */
	public function __construct(
		public readonly string $mode,
		public readonly int $succeeded,
		public readonly array $failures = [],
		public readonly ?string $targetFolder = null,
		public readonly ?string $error = null,
	) {
	}

	public function jsonSerialize(): array {
		return [
			'mode' => $this->mode,
			'succeeded' => $this->succeeded,
			'failed' => count($this->failures),
			'failures' => $this->failures,
			'targetFolder' => $this->targetFolder,
			'error' => $this->error,
		];
	}
}
