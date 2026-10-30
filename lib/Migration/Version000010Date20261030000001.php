<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Importing from another NextTime database (docs/proposals/data-sources.md).
 *
 * `source_ref` marks a row that was imported and says where from, so running an import twice never adds
 * a row twice. `approved_before_at` marks a claim that was already approved in the old system; it is
 * imported as pending and an approver can see the history. `timebank_imports` is one row per import
 * (who staged it, who decided it, the counts, and an admin-only report); `timebank_import_staged` holds
 * the rows of a staged import until a second admin finalizes it, and is emptied when it is finalized,
 * cancelled or expires.
 */
class Version000010Date20261030000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		foreach (['timebank_requests' => 'tb_req_srcref', 'timebank_volunteers' => 'tb_vol_srcref', 'timebank_earnings' => 'tb_earn_srcref'] as $name => $index) {
			$table = $schema->getTable($name);
			if (!$table->hasColumn('source_ref')) {
				$table->addColumn('source_ref', Types::STRING, ['notnull' => false, 'length' => 190]);
				$table->addUniqueIndex(['source_ref'], $index);
			}
		}

		$earnings = $schema->getTable('timebank_earnings');
		if (!$earnings->hasColumn('approved_before_at')) {
			$earnings->addColumn('approved_before_at', Types::DATETIME, ['notnull' => false]);
		}

		if (!$schema->hasTable('timebank_imports')) {
			$table = $schema->createTable('timebank_imports');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			// staged, finalized, cancelled or expired
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			// pgsql, mysql or sqlite
			$table->addColumn('source_kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			// a name the admin chose; never a host, a path or a credential
			$table->addColumn('source_label', Types::STRING, ['notnull' => true, 'length' => 100]);
			$table->addColumn('staged_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('staged_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('decided_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('decided_at', Types::DATETIME, ['notnull' => false]);
			// totals, as JSON; shown in the public governance log
			$table->addColumn('counts', Types::TEXT, ['notnull' => false]);
			// who and what did not match, as JSON; admins only
			$table->addColumn('report', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['status'], 'tb_imports_status_idx');
		}

		if (!$schema->hasTable('timebank_import_staged')) {
			$table = $schema->createTable('timebank_import_staged');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('import_id', Types::INTEGER, ['notnull' => true]);
			// request, volunteer or earning
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('source_ref', Types::STRING, ['notnull' => true, 'length' => 190]);
			$table->addColumn('data', Types::TEXT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['import_id'], 'tb_staged_import_idx');
		}

		return $schema;
	}
}
