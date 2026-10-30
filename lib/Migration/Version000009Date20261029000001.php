<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Votes on large adjustments that a facilitator or national organizer proposed (security plan,
 * Phase 4). One vote per person per adjustment, enforced here so two votes sent at the same moment
 * cannot both count. Adjustments themselves gain only a new status value, `voting`.
 */
class Version000009Date20261029000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('timebank_adjustment_votes')) {
			$table = $schema->createTable('timebank_adjustment_votes');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('adjustment_id', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('voter_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// approve or reject
			$table->addColumn('vote', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['adjustment_id', 'voter_id'], 'timebank_adjvote_once_idx');
		}

		return $schema;
	}
}
