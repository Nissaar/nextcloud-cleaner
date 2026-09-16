<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Db;

use OCP\AppFramework\Db\Entity;

/**
 * How far one user's index scan has got.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCursorFileId()
 * @method void setCursorFileId(int $cursorFileId)
 * @method bool getComplete()
 * @method void setComplete(bool $complete)
 * @method bool getRunning()
 * @method void setRunning(bool $running)
 * @method int getFound()
 * @method void setFound(int $found)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method string|null getError()
 * @method void setError(?string $error)
 */
class Scan extends Entity implements \JsonSerializable {
	protected $userId = '';
	protected $cursorFileId = 0;
	protected $complete = false;
	protected $running = false;
	protected $found = 0;
	protected $startedAt = 0;
	protected $updatedAt = 0;
	protected $error = null;

	public function __construct() {
		$this->addType('cursorFileId', 'integer');
		$this->addType('complete', 'boolean');
		$this->addType('running', 'boolean');
		$this->addType('found', 'integer');
		$this->addType('startedAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'complete' => $this->getComplete(),
			'running' => $this->getRunning(),
			'found' => $this->getFound(),
			'startedAt' => $this->getStartedAt(),
			'updatedAt' => $this->getUpdatedAt(),
			'error' => $this->getError(),
		];
	}
}
