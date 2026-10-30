<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * `show_username`: the poster chose to show their username on this request to every member. Off by
 * default, so requests posted before this column existed keep the poster private; RequestPresenter
 * decides who sees it anyway (facilitators, national organizers, admins, accepted volunteers).
 */
class Version000011Date20261031000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('timebank_requests');
		if (!$table->hasColumn('show_username')) {
			$table->addColumn('show_username', Types::BOOLEAN, ['notnull' => true, 'default' => false]);

			return $schema;
		}

		return null;
	}
}
