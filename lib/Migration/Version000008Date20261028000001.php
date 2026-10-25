<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Request geography (security plan, Phase 2): every in-person request carries a ZIP, which decides
 * its state (chapter) unless someone reassigned it. Also the columns later phases need, so they
 * share one migration: remote mode with a linked file (Phase 5) and the review queue (Phase 3).
 *
 * Existing requests get no ZIP and so no state until their requester edits them; the Chapters page
 * counts them.
 */
class Version000008Date20261028000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$requests = $schema->getTable('timebank_requests');
		if (!$requests->hasColumn('zip')) {
			$requests->addColumn('zip', Types::STRING, ['notnull' => false, 'length' => 5]);
		}
		// The state (chapter slug) the request belongs to: from the ZIP, or the override.
		if (!$requests->hasColumn('chapter')) {
			$requests->addColumn('chapter', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		if (!$requests->hasColumn('chapter_override')) {
			$requests->addColumn('chapter_override', Types::STRING, ['notnull' => false, 'length' => 64]);
		}
		// in_person or remote
		if (!$requests->hasColumn('mode')) {
			$requests->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'in_person']);
		}
		if (!$requests->hasColumn('resource_file_id')) {
			$requests->addColumn('resource_file_id', Types::BIGINT, ['notnull' => false]);
		}
		// published, awaiting_review or declined
		if (!$requests->hasColumn('review_status')) {
			$requests->addColumn('review_status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'published']);
		}
		if (!$requests->hasIndex('timebank_req_chapter_idx')) {
			$requests->addIndex(['chapter'], 'timebank_req_chapter_idx');
		}

		$rows = $schema->getTable('timebank_layer_rows');
		if (!$rows->hasColumn('chapter_override')) {
			$rows->addColumn('chapter_override', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		return $schema;
	}
}
