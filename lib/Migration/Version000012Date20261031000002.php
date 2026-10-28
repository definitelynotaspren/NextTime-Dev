<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `agreed_hours`: the hours the requester and a volunteer settled on when the offer was accepted.
 * Empty on offers not yet accepted and on offers accepted before this column existed; those use the
 * offered hours.
 */
class Version000012Date20261031000002 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('timebank_volunteers');
		if (!$table->hasColumn('agreed_hours')) {
			$table->addColumn('agreed_hours', Types::DECIMAL, ['notnull' => false, 'precision' => 5, 'scale' => 2]);

			return $schema;
		}

		return null;
	}
}
