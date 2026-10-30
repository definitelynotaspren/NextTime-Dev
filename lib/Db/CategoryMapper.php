<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Category>
 */
class CategoryMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'timebank_categories', Category::class);
	}

	/**
	 * @return Category[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): Category {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * How the category is being used right now. Unfilled open requests (no offers) and
	 * cancelled ones do not count as use.
	 *
	 * @return array{inProgress: int, openWithOffers: int, claimsInFlight: int}
	 */
	public function activeUse(int $id): array {
		$param = fn (IQueryBuilder $qb): IParameter => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT);

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('timebank_requests')
			->where($qb->expr()->eq('category_id', $param($qb)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('in_progress')));
		$inProgress = $this->fetchCount($qb);

		$qb = $this->db->getQueryBuilder();
		$sub = $this->db->getQueryBuilder();
		$sub->select('request_id')
			->from('timebank_volunteers')
			->where($sub->expr()->in('status', $qb->createNamedParameter(['offered', 'accepted'], IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->select($qb->func()->count('*'))
			->from('timebank_requests')
			->where($qb->expr()->eq('category_id', $param($qb)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('open')))
			->andWhere($qb->expr()->in('id', $qb->createFunction('(' . $sub->getSQL() . ')')));
		$openWithOffers = $this->fetchCount($qb);

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('timebank_earnings')
			->where($qb->expr()->eq('category_id', $param($qb)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'voting'], IQueryBuilder::PARAM_STR_ARRAY)));
		$claims = $this->fetchCount($qb);

		return ['inProgress' => $inProgress, 'openWithOffers' => $openWithOffers, 'claimsInFlight' => $claims];
	}

	/**
	 * Rows anywhere that point at this category, including finished history.
	 */
	public function countReferences(int $id): int {
		$total = 0;
		foreach (['timebank_requests', 'timebank_earnings', 'timebank_user_stats'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))
				->from($table)
				->where($qb->expr()->eq('category_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$total += $this->fetchCount($qb);
		}

		return $total;
	}

	public function findOneByName(string $name): ?Category {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)));

		$rows = $this->findEntities($qb);

		return $rows[0] ?? null;
	}

	private function fetchCount(IQueryBuilder $qb): int {
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}
}
