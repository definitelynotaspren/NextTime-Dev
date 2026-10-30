<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Community flagging of comments, and soft removal by moderators.
 */
class Version000004Date20261024000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('timebank_comment_flags')) {
			$table = $schema->createTable('timebank_comment_flags');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('comment_id', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('reason', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			// Set when a moderator dismisses the flags; dismissed flags no longer count.
			$table->addColumn('dismissed_at', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['comment_id', 'user_id'], 'timebank_flag_once_idx');
		}

		$comments = $schema->getTable('timebank_comments');
		if (!$comments->hasColumn('removed_at')) {
			$comments->addColumn('removed_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$comments->addColumn('removed_by', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
		}

		return $schema;
	}
}
