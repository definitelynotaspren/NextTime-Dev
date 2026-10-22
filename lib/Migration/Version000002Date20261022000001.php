<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds coordinates to requests and creates the layer-row table used by the
 * organizer desk.
 */
class Version000002Date20261022000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('timebank_requests')) {
			$table = $schema->getTable('timebank_requests');
			if (!$table->hasColumn('latitude')) {
				$table->addColumn('latitude', Types::FLOAT, [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('longitude')) {
				$table->addColumn('longitude', Types::FLOAT, [
					'notnull' => false,
				]);
			}
		}

		if (!$schema->hasTable('timebank_layer_rows')) {
			$table = $schema->createTable('timebank_layer_rows');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('layer', Types::STRING, [
				'notnull' => true,
				'length' => 40,
			]);
			$table->addColumn('uid', Types::STRING, [
				'notnull' => true,
				'length' => 40,
			]);
			$table->addColumn('chapter', Types::STRING, [
				'notnull' => false,
				'length' => 40,
			]);
			$table->addColumn('publish', Types::BOOLEAN, [
				'notnull' => true,
				'default' => false,
			]);
			$table->addColumn('source', Types::STRING, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('data', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('assigned_to', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('assignment_status', Types::STRING, [
				'notnull' => false,
				'length' => 20,
			]);
			$table->addColumn('assigned_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('created_by', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['layer', 'uid'], 'timebank_lr_uid_idx');
			$table->addIndex(['layer', 'source'], 'timebank_lr_source_idx');
			$table->addIndex(['assigned_to'], 'timebank_lr_assignee_idx');
		}

		return $schema;
	}
}
