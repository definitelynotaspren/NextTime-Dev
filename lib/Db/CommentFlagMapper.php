<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<CommentFlag>
 */
class CommentFlagMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'timebank_comment_flags', CommentFlag::class);
	}

	/**
	 * Flags still counting (not dismissed) on any of the comments.
	 *
	 * @param int[] $commentIds
	 * @return CommentFlag[]
	 */
	public function findActiveByComments(array $commentIds): array {
		if ($commentIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('comment_id', $qb->createNamedParameter($commentIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('dismissed_at'))
			->orderBy('created_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Every flag a member has ever put on this comment, dismissed or not (one per member).
	 */
	public function findByCommentAndUser(int $commentId, string $userId): ?CommentFlag {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$rows = $this->findEntities($qb);

		return $rows[0] ?? null;
	}

	/**
	 * @return CommentFlag[]
	 */
	public function findActiveByComment(int $commentId): array {
		return $this->findActiveByComments([$commentId]);
	}

	/**
	 * Comment ids that currently have at least one counting flag.
	 *
	 * @return int[]
	 */
	public function findFlaggedCommentIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('comment_id')
			->from($this->getTableName())
			->where($qb->expr()->isNull('dismissed_at'));

		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $ids;
	}
}
