<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Remembers which Nextcloud Forms submissions have been looked at, so each is imported once
 * and a rejected one is not retried until someone asks.
 */
class Version000007Date20261027000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('timebank_intake_log')) {
			$table = $schema->createTable('timebank_intake_log');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('form_id', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('submission_id', Types::INTEGER, [
				'notnull' => true,
			]);
			// imported or rejected
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('layer', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// The desk row's ID, for imported submissions.
			$table->addColumn('uid', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('reason', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['form_id', 'submission_id'], 'timebank_intake_once_idx');
		}

		return $schema;
	}
}
