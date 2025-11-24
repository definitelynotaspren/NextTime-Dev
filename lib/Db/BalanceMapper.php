<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Balance>
 */
class BalanceMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'timebank_balances', Balance::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByUserId(string $userId): Balance {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * @return Balance[]
	 */
	public function findAll(int $limit = 100, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('balance', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities($qb);
	}

	public function insertBalance(Balance $balance): Balance {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->values([
				'user_id' => $qb->createNamedParameter($balance->getUserId()),
				'balance' => $qb->createNamedParameter($balance->getBalance()),
				'updated_at' => $qb->createNamedParameter($balance->getUpdatedAt(), 'datetime'),
			])
			->executeStatement();

		return $balance;
	}

	public function updateBalance(Balance $balance): Balance {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('balance', $qb->createNamedParameter($balance->getBalance()))
			->set('updated_at', $qb->createNamedParameter($balance->getUpdatedAt(), 'datetime'))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($balance->getUserId())))
			->executeStatement();

		return $balance;
	}
}
