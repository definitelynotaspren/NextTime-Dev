<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<UserStats>
 */
class UserStatsMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'timebank_user_stats', UserStats::class);
	}

	/**
	 * @return UserStats|null
	 */
	public function findByUserAndCategory(string $userId, int $categoryId): ?UserStats {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * @return UserStats[]
	 */
	public function findByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('completed_requests', 'DESC');

		return $this->findEntities($qb);
	}

	public function incrementStats(string $userId, int $categoryId, float $hours): void {
		$stats = $this->findByUserAndCategory($userId, $categoryId);

		if ($stats === null) {
			$stats = new UserStats();
			$stats->setUserId($userId);
			$stats->setCategoryId($categoryId);
			$stats->setCompletedRequests(1);
			$stats->setTotalHoursProvided((string)$hours);
			$stats->setLastActive(new \DateTime());
			$this->insert($stats);
		} else {
			$stats->setCompletedRequests($stats->getCompletedRequests() + 1);
			$stats->setTotalHoursProvided((string)((float)$stats->getTotalHoursProvided() + $hours));
			$stats->setLastActive(new \DateTime());
			$this->update($stats);
		}
	}
}
