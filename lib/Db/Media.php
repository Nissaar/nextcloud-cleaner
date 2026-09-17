<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudCleaner\Db;

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
	/*
	 * Every property below is deliberately left uninitialised rather than given a
	 * sensible-looking default.
	 *
	 * `Entity::setter()` returns early when the value being set is identical to the
	 * property's current value, and an early return means the field is never marked
	 * dirty — so `QBMapper::insert()` leaves that column out of the INSERT entirely.
	 * Any property whose default matched a real value would silently write a null.
	 * Starting from null means no real value can ever collide with the default.
	 */
	protected $userId = null;
	protected $fileId = null;
	protected $takenAt = null;
	protected $yearMonth = null;
	protected $isVideo = null;
	protected $mimetype = null;
	protected $name = null;
	protected $path = null;
	protected $size = null;
	protected $dateSource = null;
	protected $indexedAt = null;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('takenAt', 'integer');
		$this->addType('isVideo', 'boolean');
		$this->addType('size', 'integer');
		$this->addType('indexedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'fileId' => (int)$this->getFileId(),
			'takenAt' => (int)$this->getTakenAt(),
			'yearMonth' => (string)$this->getYearMonth(),
			'isVideo' => (bool)$this->getIsVideo(),
			'mimetype' => (string)$this->getMimetype(),
			'name' => (string)$this->getName(),
			'path' => (string)$this->getPath(),
			'size' => (int)$this->getSize(),
			'dateSource' => (string)$this->getDateSource(),
		];
	}
}
