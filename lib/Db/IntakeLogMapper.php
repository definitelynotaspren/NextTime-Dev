<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<IntakeLog>
 */
class IntakeLogMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'timebank_intake_log', IntakeLog::class);
	}

	/**
	 * Submission ids of a form that were already imported or rejected.
	 *
	 * @return list<int>
	 */
	public function processedIds(int $formId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('submission_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$ids = [];
		foreach ($result->fetchAll(\PDO::FETCH_COLUMN) as $id) {
			$ids[] = (int)$id;
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * @return IntakeLog[]
	 */
	public function findRecent(int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/** Forget the rejections so the next check looks at those submissions again. */
	public function clearRejected(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('rejected')));

		return $qb->executeStatement();
	}
}
