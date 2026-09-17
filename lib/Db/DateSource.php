<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudCleaner\Db;

/**
 * Where a media item's capture date came from, best first.
 *
 * Recorded per item rather than assumed globally, because a single library routinely
 * mixes all four: phone uploads carry EXIF, screenshots carry only a filename date,
 * and anything copied over a network share has nothing but an mtime.
 */
final class DateSource {
	/** Photos app extracted an EXIF DateTimeOriginal. */
	public const EXIF = 'exif';
	/** Parsed out of a name like IMG_20240712_140325.jpg. */
	public const FILENAME = 'filename';
	/** The file's modification time. */
	public const MTIME = 'mtime';
	/** Nothing better was available; when the file arrived on the server. */
	public const UPLOAD = 'upload';
}
