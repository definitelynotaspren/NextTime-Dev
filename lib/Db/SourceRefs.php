<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\QBMapper;

/**
 * Which rows of a table came from an import, found by the text every source reference of one source
 * starts with. Lets an import skip rows it already brought in.
 *
 * @psalm-require-extends QBMapper
 */
trait SourceRefs {

	/**
	 * @return array<string, true>
	 */
	public function sourceRefsStartingWith(string $prefix): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('source_ref')
			->from($this->getTableName())
			->where($qb->expr()->like('source_ref', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . ':%')));

		$refs = [];
		$result = $qb->executeQuery();
		while (($ref = $result->fetchOne()) !== false) {
			if (is_string($ref)) {
				$refs[$ref] = true;
			}
		}
		$result->closeCursor();

		return $refs;
	}
}
