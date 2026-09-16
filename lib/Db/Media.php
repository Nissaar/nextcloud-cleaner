<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One indexed photo or video.
 *
 * Deliberately holds no image bytes and no thumbnail: previews are served by core's
 * own preview endpoint straight from the file, so this table stays small enough to
 * hold a six-figure library without anyone noticing.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method int getTakenAt()
 * @method void setTakenAt(int $takenAt)
 * @method string getYearMonth()
 * @method void setYearMonth(string $yearMonth)
 * @method bool getIsVideo()
 * @method void setIsVideo(bool $isVideo)
 * @method string getMimetype()
 * @method void setMimetype(string $mimetype)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getDateSource()
 * @method void setDateSource(string $dateSource)
 * @method int getIndexedAt()
 * @method void setIndexedAt(int $indexedAt)
 */
class Media extends Entity implements \JsonSerializable {
	protected $userId = '';
	protected $fileId = 0;
	protected $takenAt = 0;
	protected $yearMonth = '';
	protected $isVideo = false;
	protected $mimetype = '';
	protected $name = '';
	protected $path = '';
	protected $size = 0;
	protected $dateSource = DateSource::MTIME;
	protected $indexedAt = 0;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('takenAt', 'integer');
		$this->addType('isVideo', 'boolean');
		$this->addType('size', 'integer');
		$this->addType('indexedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'fileId' => $this->getFileId(),
			'takenAt' => $this->getTakenAt(),
			'yearMonth' => $this->getYearMonth(),
			'isVideo' => $this->getIsVideo(),
			'mimetype' => $this->getMimetype(),
			'name' => $this->getName(),
			'path' => $this->getPath(),
			'size' => $this->getSize(),
			'dateSource' => $this->getDateSource(),
		];
	}
}
