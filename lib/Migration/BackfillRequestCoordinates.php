<?php

declare(strict_types=1);

namespace OCA\TimeBank\Migration;

use OCA\TimeBank\Geo\Coordinates;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Copies "lat, lng" location text into the latitude/longitude columns for
 * requests created before coordinates existed. Free-text addresses are left
 * alone; they cannot be placed without geocoding.
 */
class BackfillRequestCoordinates implements IRepairStep {

	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	public function getName(): string {
		return 'Backfill request coordinates from "lat, lng" location text';
	}

	public function run(IOutput $output): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'location')
			->from('timebank_requests')
			->where($qb->expr()->isNotNull('location'))
			->andWhere($qb->expr()->isNull('latitude'));

		$result = $qb->executeQuery();
		/** @var list<array<string, mixed>> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();

		$updated = 0;
		foreach ($rows as $row) {
			$point = Coordinates::parseText((string)$row['location']);
			if ($point === null) {
				continue;
			}

			$update = $this->db->getQueryBuilder();
			$update->update('timebank_requests')
				->set('latitude', $update->createNamedParameter($point[0], IQueryBuilder::PARAM_STR))
				->set('longitude', $update->createNamedParameter($point[1], IQueryBuilder::PARAM_STR))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
			$updated++;
		}

		$output->info("Backfilled coordinates for $updated request(s).");
	}
}
