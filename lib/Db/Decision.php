<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A verdict you gave one file.
 *
 * `applied` is the important column: until it is true nothing on disk has been
 * touched, and the row can be withdrawn freely. Once it is true the row stops being
 * a plan and becomes history — the record an undo is built from.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getVerdict()
 * @method void setVerdict(string $verdict)
 * @method int getDecidedAt()
 * @method void setDecidedAt(int $decidedAt)
 * @method bool getApplied()
 * @method void setApplied(bool $applied)
 * @method int|null getAppliedAt()
 * @method void setAppliedAt(?int $appliedAt)
 * @method string|null getAppliedMode()
 * @method void setAppliedMode(?string $appliedMode)
 * @method int getTakenAt()
 * @method void setTakenAt(int $takenAt)
 * @method string getYearMonth()
 * @method void setYearMonth(string $yearMonth)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string|null getOriginPath()
 * @method void setOriginPath(?string $originPath)
 * @method bool getIsVideo()
 * @method void setIsVideo(bool $isVideo)
 */
class Decision extends Entity implements \JsonSerializable {
	protected $userId = '';
	protected $fileId = 0;
	protected $verdict = Verdict::KEEP;
	protected $decidedAt = 0;
	protected $applied = false;
	protected $appliedAt = null;
	protected $appliedMode = null;
	protected $takenAt = 0;
	protected $yearMonth = '';
	protected $name = '';
	protected $size = 0;
	protected $originPath = null;
	protected $isVideo = false;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('decidedAt', 'integer');
		$this->addType('applied', 'boolean');
		$this->addType('appliedAt', 'integer');
		$this->addType('takenAt', 'integer');
		$this->addType('size', 'integer');
		$this->addType('isVideo', 'boolean');
	}

	public function jsonSerialize(): array {
		return [
			'fileId' => $this->getFileId(),
			'verdict' => $this->getVerdict(),
			'decidedAt' => $this->getDecidedAt(),
			'applied' => $this->getApplied(),
			'appliedAt' => $this->getAppliedAt(),
			'appliedMode' => $this->getAppliedMode(),
			'takenAt' => $this->getTakenAt(),
			'yearMonth' => $this->getYearMonth(),
			'name' => $this->getName(),
			'size' => $this->getSize(),
			'originPath' => $this->getOriginPath(),
			'isVideo' => $this->getIsVideo(),
		];
	}
}
