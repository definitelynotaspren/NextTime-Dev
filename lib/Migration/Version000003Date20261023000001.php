<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the log of withdrawn help (a volunteer stepping back after being accepted).
 */
class Version000003Date20261023000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('timebank_withdrawals')) {
			$table = $schema->createTable('timebank_withdrawals');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('request_id', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('volunteer_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('offer_status', Types::STRING, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('proposed_hours', Types::DECIMAL, [
				'notnull' => true,
				'precision' => 5,
				'scale' => 2,
			]);
			$table->addColumn('reason', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['request_id'], 'timebank_wd_request_idx');
			$table->addIndex(['volunteer_id'], 'timebank_wd_volunteer_idx');
		}

		return $schema;
	}
}
