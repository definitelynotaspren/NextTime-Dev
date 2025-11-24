<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class SeedCategories implements IRepairStep {

	private IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	public function getName(): string {
		return 'Initialize default timebank categories';
	}

	public function run(IOutput $output): void {
		// Check if categories already exist
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('timebank_categories');
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		if ($count > 0) {
			$output->info('Categories already seeded, skipping');
			return;
		}

		$defaultCategories = [
			['name' => 'Transportation', 'description' => 'Rides, errands, deliveries', 'earn_rate' => '1.00', 'icon' => 'car'],
			['name' => 'Childcare', 'description' => 'Babysitting, tutoring children', 'earn_rate' => '1.00', 'icon' => 'baby'],
			['name' => 'Home Repair', 'description' => 'Fixing, building, maintenance', 'earn_rate' => '1.20', 'icon' => 'wrench'],
			['name' => 'Gardening', 'description' => 'Yard work, landscaping', 'earn_rate' => '1.00', 'icon' => 'flower'],
			['name' => 'Tech Support', 'description' => 'Computer help, tech issues', 'earn_rate' => '1.50', 'icon' => 'laptop'],
			['name' => 'Cooking/Food', 'description' => 'Meal prep, food sharing', 'earn_rate' => '1.00', 'icon' => 'food'],
			['name' => 'Administrative', 'description' => 'Paperwork, organizing', 'earn_rate' => '1.00', 'icon' => 'clipboard'],
			['name' => 'Health/Wellness', 'description' => 'Fitness, care, support', 'earn_rate' => '1.30', 'icon' => 'heart'],
			['name' => 'Education', 'description' => 'Teaching, tutoring, training', 'earn_rate' => '1.40', 'icon' => 'book'],
			['name' => 'Other', 'description' => 'Miscellaneous services', 'earn_rate' => '1.00', 'icon' => 'star'],
		];

		$now = new \DateTime();

		foreach ($defaultCategories as $category) {
			$qb = $this->connection->getQueryBuilder();
			$qb->insert('timebank_categories')
				->values([
					'name' => $qb->createNamedParameter($category['name']),
					'description' => $qb->createNamedParameter($category['description']),
					'earn_rate' => $qb->createNamedParameter($category['earn_rate']),
					'icon' => $qb->createNamedParameter($category['icon']),
					'created_at' => $qb->createNamedParameter($now, 'datetime'),
				])
				->executeStatement();
		}

		$output->info('Initialized ' . count($defaultCategories) . ' default categories');
	}
}
