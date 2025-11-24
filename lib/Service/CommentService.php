<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\Comment;
use OCA\TimeBank\Db\CommentMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class CommentService {

	private CommentMapper $commentMapper;

	public function __construct(CommentMapper $commentMapper) {
		$this->commentMapper = $commentMapper;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create(int $requestId, string $userId, array $data): Comment {
		$comment = new Comment();
		$comment->setRequestId($requestId);
		$comment->setUserId($userId);
		$comment->setComment($data['comment']);
		$comment->setParentId($data['parentId'] ?? null);
		$comment->setCreatedAt(new \DateTime());

		return $this->commentMapper->insert($comment);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function delete(int $commentId, string $userId): void {
		$comment = $this->commentMapper->find($commentId);

		if ($comment->getUserId() !== $userId) {
			throw new \Exception('Not authorized to delete this comment');
		}

		$this->commentMapper->delete($comment);
	}

	/**
	 * @return Comment[]
	 */
	public function getByRequest(int $requestId): array {
		return $this->commentMapper->findByRequest($requestId);
	}
}
