<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Remembers how far the reminders for a pending claim have got.
 */
class Version000006Date20261026000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$earnings = $schema->getTable('timebank_earnings');
		if (!$earnings->hasColumn('notified_stage')) {
			// 0 told when submitted, 1 reminded after 3 days, 2 admins told after 7 days
			$earnings->addColumn('notified_stage', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
		}

		return $schema;
	}
}
