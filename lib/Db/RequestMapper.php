<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Request>
 */
class RequestMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'timebank_requests', Request::class);
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return Request[]
	 */
	public function findAll(array $filters = [], int $limit = 50, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->setMaxResults($limit)
			->setFirstResult($offset)
			->orderBy('created_at', 'DESC');

		if (isset($filters['status']) && $filters['status'] !== null) {
			$qb->andWhere($qb->expr()->eq('status',
				$qb->createNamedParameter($filters['status'])));
		}

		if (isset($filters['categoryId']) && $filters['categoryId'] !== null) {
			$qb->andWhere($qb->expr()->eq('category_id',
				$qb->createNamedParameter($filters['categoryId'], IQueryBuilder::PARAM_INT)));
		}

		if (isset($filters['priority']) && $filters['priority'] !== null) {
			$qb->andWhere($qb->expr()->eq('priority',
				$qb->createNamedParameter($filters['priority'])));
		}

		if (isset($filters['requesterId']) && $filters['requesterId'] !== null) {
			$qb->andWhere($qb->expr()->eq('requester_id',
				$qb->createNamedParameter($filters['requesterId'])));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Requests not yet finished that have no ZIP, so no state: posted before ZIPs were required.
	 * They stay unassigned until the requester edits them. Shown on the Chapters page.
	 */
	public function countActiveWithoutZip(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))
			->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(['open', 'in_progress'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNull('zip'));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Open requests with a ZIP, newest first. Used by the map feed.
	 *
	 * @return Request[]
	 */
	public function findOpenWithZip(int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->isNotNull('zip'))
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Request {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public function count(array $filters = []): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName());

		if (isset($filters['status']) && $filters['status'] !== null) {
			$qb->andWhere($qb->expr()->eq('status',
				$qb->createNamedParameter($filters['status'])));
		}

		if (isset($filters['categoryId']) && $filters['categoryId'] !== null) {
			$qb->andWhere($qb->expr()->eq('category_id',
				$qb->createNamedParameter($filters['categoryId'], IQueryBuilder::PARAM_INT)));
		}

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}
}
