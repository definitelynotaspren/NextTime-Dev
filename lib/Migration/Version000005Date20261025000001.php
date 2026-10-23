<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Admin tools: archiving categories, balance adjustment proposals that need a second
 * person, and the public governance log.
 */
class Version000005Date20261025000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$categories = $schema->getTable('timebank_categories');
		if (!$categories->hasColumn('archived_at')) {
			$categories->addColumn('archived_at', Types::DATETIME, [
				'notnull' => false,
			]);
		}

		if (!$schema->hasTable('timebank_adjustments')) {
			$table = $schema->createTable('timebank_adjustments');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// Signed: positive credits the member, negative debits.
			$table->addColumn('hours', Types::DECIMAL, [
				'notnull' => true,
				'precision' => 7,
				'scale' => 2,
			]);
			$table->addColumn('reason', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('proposer_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			// pending, approved, rejected, withdrawn, expired
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 20,
			]);
			// Empty for small adjustments, which one admin can make alone.
			$table->addColumn('approver_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('decision_note', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('expires_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('decided_at', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['status'], 'timebank_adj_status_idx');
			$table->addIndex(['user_id'], 'timebank_adj_user_idx');
		}

		if (!$schema->hasTable('timebank_governance_log')) {
			$table = $schema->createTable('timebank_governance_log');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('actor_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('action', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('subject', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			// JSON: old and new values, reasons.
			$table->addColumn('details', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['created_at'], 'timebank_gov_created_idx');
		}

		return $schema;
	}
}
