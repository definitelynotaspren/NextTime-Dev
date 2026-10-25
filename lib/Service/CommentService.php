<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Chapter\NationalOrganizers;
use OCA\TimeBank\Db\Comment;
use OCA\TimeBank\Db\CommentFlag;
use OCA\TimeBank\Db\CommentFlagMapper;
use OCA\TimeBank\Db\CommentMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;

/**
 * Comments are moderated by the community first: members flag, and at FLAG_LIMIT distinct
 * flags a comment is hidden from members (not deleted). Moderators, meaning Nextcloud
 * admins and national organizers, can remove it or dismiss the flags. Authors cannot
 * delete their own comments. Removal is soft, so there is always an audit trail.
 */
class CommentService {

	/** The same number as the community votes needed to approve a claim. */
	public const int FLAG_LIMIT = 3;

	public const int MAX_LENGTH = 2000;
	public const int MAX_REASON_LENGTH = 500;

	public function __construct(
		private CommentMapper $commentMapper,
		private CommentFlagMapper $flagMapper,
		private IGroupManager $groups,
		private NationalOrganizers $nationalOrganizers,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws InvalidStateException
	 */
	public function create(int $requestId, string $userId, array $data): Comment {
		$text = trim((string)($data['comment'] ?? ''));
		if ($text === '') {
			throw new InvalidStateException('A comment cannot be empty');
		}
		if (mb_strlen($text) > self::MAX_LENGTH) {
			throw new InvalidStateException('A comment cannot exceed ' . self::MAX_LENGTH . ' characters');
		}

		$comment = new Comment();
		$comment->setRequestId($requestId);
		$comment->setUserId($userId);
		$comment->setComment($text);
		$comment->setParentId(Input::int($data['parentId'] ?? null));
		$comment->setCreatedAt(new \DateTime());

		return $this->commentMapper->insert($comment);
	}

	public function isModerator(string $userId): bool {
		return $this->groups->isAdmin($userId) || $this->nationalOrganizers->isNationalOrganizer($userId);
	}

	/**
	 * Comments of a request as this viewer may see them. Hidden and removed comments keep
	 * their place in the thread but not their text, except for moderators.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getForViewer(int $requestId, string $viewerId): array {
		$comments = $this->commentMapper->findByRequest($requestId);
		$moderator = $this->isModerator($viewerId);
		$flags = $this->groupFlags($comments);

		return array_values(array_map(
			fn (Comment $c) => $this->view($c, $flags[$c->getId()] ?? [], $viewerId, $moderator),
			$comments,
		));
	}

	/**
	 * Every comment with counting flags, with the reasons. Moderators only.
	 *
	 * @return list<array<string, mixed>>
	 * @throws NotAuthorizedException
	 */
	public function getFlagged(string $moderatorId): array {
		$this->requireModerator($moderatorId);

		$rows = [];
		foreach ($this->flagMapper->findFlaggedCommentIds() as $id) {
			try {
				$comment = $this->commentMapper->find($id);
			} catch (DoesNotExistException) {
				continue;
			}
			if ($comment->getRemovedAt() !== null) {
				continue;
			}
			$flags = $this->flagMapper->findActiveByComment($id);
			$rows[] = $this->view($comment, $flags, $moderatorId, true);
		}

		return $rows;
	}

	/**
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 */
	public function flag(int $commentId, string $userId, ?string $reason): CommentFlag {
		$comment = $this->commentMapper->find($commentId);

		if ($comment->getUserId() === $userId) {
			throw new InvalidStateException('You cannot flag your own comment');
		}
		if ($comment->getRemovedAt() !== null) {
			throw new InvalidStateException('This comment was already removed');
		}
		if ($this->flagMapper->findByCommentAndUser($commentId, $userId) !== null) {
			throw new InvalidStateException('You have already flagged this comment');
		}

		$reason = $reason === null ? null : trim($reason);
		if ($reason !== null && mb_strlen($reason) > self::MAX_REASON_LENGTH) {
			throw new InvalidStateException('A reason cannot exceed ' . self::MAX_REASON_LENGTH . ' characters');
		}

		$flag = new CommentFlag();
		$flag->setCommentId($commentId);
		$flag->setUserId($userId);
		$flag->setReason($reason === '' ? null : $reason);
		$flag->setCreatedAt(new \DateTime());

		return $this->flagMapper->insert($flag);
	}

	/**
	 * Moderator removal. The text stays in the database for the audit trail.
	 *
	 * @throws DoesNotExistException
	 * @throws NotAuthorizedException
	 */
	public function remove(int $commentId, string $moderatorId): void {
		$this->requireModerator($moderatorId);
		$comment = $this->commentMapper->find($commentId);

		if ($comment->getRemovedAt() !== null) {
			return;
		}
		$comment->setRemovedAt(new \DateTime());
		$comment->setRemovedBy($moderatorId);
		$this->commentMapper->update($comment);
	}

	/**
	 * Moderator decides the comment is fine: the current flags stop counting and the
	 * comment is shown again.
	 *
	 * @throws DoesNotExistException
	 * @throws NotAuthorizedException
	 */
	public function dismissFlags(int $commentId, string $moderatorId): void {
		$this->requireModerator($moderatorId);
		$this->commentMapper->find($commentId);

		foreach ($this->flagMapper->findActiveByComment($commentId) as $flag) {
			$flag->setDismissedAt(new \DateTime());
			$this->flagMapper->update($flag);
		}
	}

	/**
	 * @param Comment[] $comments
	 * @return array<int, CommentFlag[]>
	 */
	private function groupFlags(array $comments): array {
		$grouped = [];
		$ids = array_map(fn (Comment $c) => $c->getId(), $comments);
		foreach ($this->flagMapper->findActiveByComments($ids) as $flag) {
			$grouped[$flag->getCommentId()][] = $flag;
		}

		return $grouped;
	}

	/**
	 * @param CommentFlag[] $flags counting flags only
	 * @return array<string, mixed>
	 */
	private function view(Comment $comment, array $flags, string $viewerId, bool $moderator): array {
		$removed = $comment->getRemovedAt() !== null;
		$hidden = !$removed && count($flags) >= self::FLAG_LIMIT;
		$state = $removed ? 'removed' : ($hidden ? 'hidden' : 'visible');

		$view = $comment->jsonSerialize();
		$view['state'] = $state;
		// Flag counts are public, like votes; who flagged and why is for moderators.
		$view['flagCount'] = count($flags);
		$view['flaggedByMe'] = $this->flaggedBy($flags, $viewerId);

		if ($state !== 'visible' && !$moderator) {
			$view['comment'] = null;
		}
		if ($removed) {
			$view['removedBy'] = $comment->getRemovedBy();
			$view['removedAt'] = $comment->getRemovedAt()?->format('Y-m-d H:i:s');
		}
		if ($moderator) {
			$view['flags'] = array_map(fn (CommentFlag $f) => $f->jsonSerialize(), $flags);
		}

		return $view;
	}

	/**
	 * @param CommentFlag[] $flags
	 */
	private function flaggedBy(array $flags, string $userId): bool {
		foreach ($flags as $flag) {
			if ($flag->getUserId() === $userId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @throws NotAuthorizedException
	 */
	private function requireModerator(string $userId): void {
		if (!$this->isModerator($userId)) {
			throw new NotAuthorizedException('Only administrators and project leads can moderate comments');
		}
	}
}
