<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Transaction>
 */
class TransactionMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'timebank_transactions', Transaction::class);
	}

	/**
	 * @return Transaction[]
	 */
	public function findAll(int $limit = 50, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities($qb);
	}

	/**
	 * @return Transaction[]
	 */
	public function findByUser(string $userId, int $limit = 50, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('from_user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->eq('to_user_id', $qb->createNamedParameter($userId))
			))
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities($qb);
	}

	public function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName());

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function countByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('from_user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->eq('to_user_id', $qb->createNamedParameter($userId))
			));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}
}
