<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<LayerRow>
 */
class LayerRowMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'timebank_layer_rows', LayerRow::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(string $layer, int $id): LayerRow {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('layer', $qb->createNamedParameter($layer)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	public function findByUid(string $layer, string $uid): ?LayerRow {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('layer', $qb->createNamedParameter($layer)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/**
	 * @param array{assigned?: string, unassigned?: bool, source?: string} $filters
	 * @return LayerRow[]
	 */
	public function findForLayer(string $layer, array $filters = [], int $limit = 200): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('layer', $qb->createNamedParameter($layer)))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		if (isset($filters['assigned'])) {
			$qb->andWhere($qb->expr()->eq('assigned_to', $qb->createNamedParameter($filters['assigned'])));
		}
		if (!empty($filters['unassigned'])) {
			$qb->andWhere($qb->expr()->isNull('assigned_to'));
		}
		if (isset($filters['source'])) {
			$qb->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($filters['source'])));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Rows assigned to one user across layers, newest assignment first.
	 *
	 * @return LayerRow[]
	 */
	public function findAssignedTo(string $userId, ?string $layer = null, ?string $source = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('assigned_to', $qb->createNamedParameter($userId)))
			->orderBy('assigned_at', 'DESC')
			->setMaxResults(500);

		if ($layer !== null) {
			$qb->andWhere($qb->expr()->eq('layer', $qb->createNamedParameter($layer)));
		}
		if ($source !== null) {
			$qb->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Published rows for a layer. Callers still apply field and chapter policy.
	 *
	 * @return LayerRow[]
	 */
	public function findPublished(string $layer): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('layer', $qb->createNamedParameter($layer)))
			->andWhere($qb->expr()->eq('publish', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id', 'DESC')
			->setMaxResults(500);

		return $this->findEntities($qb);
	}
}
