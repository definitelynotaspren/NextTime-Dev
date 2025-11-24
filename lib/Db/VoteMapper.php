<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Vote>
 */
class VoteMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'timebank_votes', Vote::class);
	}

	/**
	 * @return Vote[]
	 */
	public function findByEarning(int $earningId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('earning_id', $qb->createNamedParameter($earningId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC');

		return $this->findEntities($qb);
	}

	public function countByEarning(int $earningId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('earning_id', $qb->createNamedParameter($earningId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function hasVoted(int $earningId, string $voterId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('earning_id', $qb->createNamedParameter($earningId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('voter_id', $qb->createNamedParameter($voterId)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}
}
