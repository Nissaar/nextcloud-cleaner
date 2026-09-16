/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'

/**
 * "2024-07" as a month a person would read.
 *
 * @param {string} yearMonth a month key like "2024-07"
 * @return {string}
 */
export function monthLabel(yearMonth) {
	const [year, month] = String(yearMonth).split('-').map(Number)
	if (!year || !month) {
		return yearMonth
	}
	return new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString(getCanonicalLocale(), {
		month: 'long',
		year: 'numeric',
		timeZone: 'UTC',
	})
}

/**
 * @param {number} seconds epoch seconds
 * @return {string}
 */
export function dateLabel(seconds) {
	if (!seconds) {
		return ''
	}
	return new Date(seconds * 1000).toLocaleDateString(getCanonicalLocale(), {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	})
}

/**
 * @param {number} bytes a size in bytes
 * @return {string}
 */
export function sizeLabel(bytes) {
	if (!bytes) {
		return ''
	}
	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	let value = bytes
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}
	return `${value < 10 && unit > 0 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`
}

/**
 * Explains where a photo's date came from, for the cases where it looks wrong.
 *
 * @param {string} source one of the DateSource values
 * @return {string}
 */
export function dateSourceLabel(source) {
	switch (source) {
		case 'exif':
			return t('photocleaner', 'Date taken, from the photo itself')
		case 'filename':
			return t('photocleaner', 'Date read from the file name')
		case 'upload':
			return t('photocleaner', 'Date this file reached the server')
		default:
			return t('photocleaner', 'Date the file was last changed — no capture date was available')
	}
}
