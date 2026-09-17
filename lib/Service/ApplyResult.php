<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudCleaner\Service;

/**
 * Why one file could not be dealt with.
 */
class Failure implements \JsonSerializable {
	public function __construct(
		public readonly int $fileId,
		public readonly string $reason,
	) {
	}

	public function jsonSerialize(): array {
		return ['fileId' => $this->fileId, 'reason' => $this->reason];
	}
}

/**
 * What came of carrying out a batch of verdicts.
 *
 * Failures are returned rather than thrown: a run where nine hundred files moved and
 * three did not is a success that needs a footnote, not an error.
 *
 * They are a list of objects rather than a map keyed by file id, because PHP encodes
 * an integer-keyed array as `{"123": "..."}` when it has entries and as `[]` when it
 * does not. A typed client cannot describe a field that changes shape with its
 * contents, so the shape is fixed here instead.
 */
class ApplyResult implements \JsonSerializable {
	/**
	 * @param Failure[] $failures
	 */
	public function __construct(
		public readonly string $mode,
		public readonly int $succeeded,
		public readonly array $failures = [],
		public readonly ?string $targetFolder = null,
		public readonly ?string $error = null,
	) {
	}

	/**
	 * @param array<int, string> $failures file id => reason
	 * @return Failure[]
	 */
	public static function failuresFrom(array $failures): array {
		$list = [];
		foreach ($failures as $fileId => $reason) {
			$list[] = new Failure((int)$fileId, $reason);
		}
		return $list;
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
