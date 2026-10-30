<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ImportStagedRow>
 */
class ImportStagedRowMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'timebank_import_staged', ImportStagedRow::class);
	}

	/**
	 * @return ImportStagedRow[] in the order they were staged
	 */
	public function findByImport(int $importId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('import_id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function deleteByImport(int $importId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('import_id', $qb->createNamedParameter($importId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
