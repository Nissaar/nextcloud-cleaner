<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PhotoCleaner\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Three tables, mirroring the three things the app has to remember: what media exists
 * and when it was taken, what you decided about each item, and how far the scan got.
 */
class Version1000Date20260916120000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('photocleaner_media')) {
			$table = $schema->createTable('photocleaner_media');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Epoch seconds. Seconds rather than millis because every Nextcloud
			// timestamp this is derived from is already in seconds.
			$table->addColumn('taken_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Precomputed in the user's timezone, so the grid never has to re-bucket.
			$table->addColumn('year_month', Types::STRING, ['notnull' => true, 'length' => 7]);
			$table->addColumn('is_video', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('mimetype', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 4000]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			// Which strategy produced taken_at, so a photo landing in an odd month is
			// explainable rather than mysterious.
			$table->addColumn('date_source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'mtime']);
			$table->addColumn('indexed_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'file_id'], 'photocleaner_media_uf');
			$table->addIndex(['user_id', 'year_month'], 'photocleaner_media_uym');
			$table->addIndex(['user_id', 'taken_at'], 'photocleaner_media_uta');
		}

		if (!$schema->hasTable('photocleaner_decisions')) {
			$table = $schema->createTable('photocleaner_decisions');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('verdict', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('decided_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Separates "you decided" from "we touched the file", which is what makes
			// the review-then-commit flow safe.
			$table->addColumn('applied', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('applied_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$table->addColumn('applied_mode', Types::STRING, ['notnull' => false, 'length' => 8]);
			// Denormalised so month progress still works once the file is gone from
			// the index.
			$table->addColumn('taken_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Also denormalised, and for the same reason the grouping is not done in
			// SQL: every database this runs on buckets timestamps into months in UTC,
			// which would put photos near a boundary in a different month than the
			// index does. Computing it once, in the user's zone, keeps the two agreeing.
			$table->addColumn('year_month', Types::STRING, ['notnull' => true, 'length' => 7, 'default' => '']);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			// Carried over so the review screen can total up what confirming would
			// free, without having to stat files that are about to be moved anyway.
			$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			// Where it lived before we moved it, which is what an undo needs.
			$table->addColumn('origin_path', Types::STRING, ['notnull' => false, 'length' => 4000]);
			$table->addColumn('is_video', Types::BOOLEAN, ['notnull' => false, 'default' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'file_id'], 'photocleaner_dec_uf');
			$table->addIndex(['user_id', 'verdict', 'applied'], 'photocleaner_dec_uva');
			$table->addIndex(['user_id', 'year_month'], 'photocleaner_dec_uym');
		}

		if (!$schema->hasTable('photocleaner_scans')) {
			$table = $schema->createTable('photocleaner_scans');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// How far into the ordered result set the scan has reached. A keyset
			// cursor on file id would resume more exactly, but Nextcloud's file
			// search only accepts eq and in on fileid, so it cannot be expressed.
			$table->addColumn('cursor_offset', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$table->addColumn('complete', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('running', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('found', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('started_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
			$table->addColumn('error', Types::STRING, ['notnull' => false, 'length' => 255]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id'], 'photocleaner_scans_u');
		}

		return $schema;
	}
}
