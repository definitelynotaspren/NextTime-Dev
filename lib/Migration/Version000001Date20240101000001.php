<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20240101000001 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Categories table
		if (!$schema->hasTable('timebank_categories')) {
			$table = $schema->createTable('timebank_categories');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('earn_rate', Types::DECIMAL, [
				'notnull' => true,
				'default' => '1.00',
				'precision' => 3,
				'scale' => 2,
			]);
			$table->addColumn('icon', Types::STRING, [
				'notnull' => false,
				'length' => 50,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['name'], 'timebank_cat_name_idx');
		}

		// Requests table
		if (!$schema->hasTable('timebank_requests')) {
			$table = $schema->createTable('timebank_requests');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('requester_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 200,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('category_id', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('hours_budget', Types::DECIMAL, [
				'notnull' => true,
				'precision' => 5,
				'scale' => 2,
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'open',
			]);
			$table->addColumn('priority', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'normal',
			]);
			$table->addColumn('deadline', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('location', Types::STRING, [
				'notnull' => false,
				'length' => 200,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['requester_id'], 'timebank_req_requester_idx');
			$table->addIndex(['category_id'], 'timebank_req_category_idx');
			$table->addIndex(['status'], 'timebank_req_status_idx');
			$table->addIndex(['created_at'], 'timebank_req_created_idx');
		}

		// Volunteers table
		if (!$schema->hasTable('timebank_volunteers')) {
			$table = $schema->createTable('timebank_volunteers');
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
			$table->addColumn('proposed_hours', Types::DECIMAL, [
				'notnull' => true,
				'precision' => 5,
				'scale' => 2,
			]);
			$table->addColumn('message', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'offered',
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['request_id'], 'timebank_vol_request_idx');
			$table->addIndex(['volunteer_id'], 'timebank_vol_volunteer_idx');
			$table->addUniqueIndex(['request_id', 'volunteer_id'], 'timebank_vol_unique_idx');
		}

		// Comments table
		if (!$schema->hasTable('timebank_comments')) {
			$table = $schema->createTable('timebank_comments');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('request_id', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('comment', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('parent_id', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['request_id'], 'timebank_com_request_idx');
			$table->addIndex(['parent_id'], 'timebank_com_parent_idx');
		}

		// Earnings table
		if (!$schema->hasTable('timebank_earnings')) {
			$table = $schema->createTable('timebank_earnings');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('category_id', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('hours_claimed', Types::DECIMAL, [
				'notnull' => true,
				'precision' => 5,
				'scale' => 2,
			]);
			$table->addColumn('actual_hours_earned', Types::DECIMAL, [
				'notnull' => true,
				'precision' => 5,
				'scale' => 2,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('evidence_file_id', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'pending',
			]);
			$table->addColumn('approver_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('rejection_reason', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('approved_at', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'timebank_earn_user_idx');
			$table->addIndex(['status'], 'timebank_earn_status_idx');
		}

		// Votes table
		if (!$schema->hasTable('timebank_votes')) {
			$table = $schema->createTable('timebank_votes');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('earning_id', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('voter_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('vote', Types::STRING, [
				'notnull' => true,
				'length' => 10,
			]);
			$table->addColumn('comment', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['earning_id'], 'timebank_vote_earning_idx');
			$table->addUniqueIndex(['earning_id', 'voter_id'], 'timebank_vote_unique_idx');
		}

		// Balances table
		if (!$schema->hasTable('timebank_balances')) {
			$table = $schema->createTable('timebank_balances');
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('balance', Types::DECIMAL, [
				'notnull' => true,
				'default' => '0.00',
				'precision' => 8,
				'scale' => 2,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['user_id']);
		}

		// Transactions table
		if (!$schema->hasTable('timebank_transactions')) {
			$table = $schema->createTable('timebank_transactions');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('from_user_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('to_user_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('hours', Types::DECIMAL, [
				'notnull' => true,
				'precision' => 8,
				'scale' => 2,
			]);
			$table->addColumn('description', Types::STRING, [
				'notnull' => true,
				'length' => 500,
			]);
			$table->addColumn('transaction_type', Types::STRING, [
				'notnull' => true,
				'length' => 30,
			]);
			$table->addColumn('reference_id', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->addColumn('reference_type', Types::STRING, [
				'notnull' => false,
				'length' => 20,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['from_user_id'], 'timebank_trans_from_idx');
			$table->addIndex(['to_user_id'], 'timebank_trans_to_idx');
			$table->addIndex(['created_at'], 'timebank_trans_created_idx');
		}

		// User stats table
		if (!$schema->hasTable('timebank_user_stats')) {
			$table = $schema->createTable('timebank_user_stats');
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('category_id', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('completed_requests', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('total_hours_provided', Types::DECIMAL, [
				'notnull' => true,
				'default' => '0.00',
				'precision' => 8,
				'scale' => 2,
			]);
			$table->addColumn('last_active', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'category_id'], 'timebank_stats_unique_idx');
		}

		return $schema;
	}
}
