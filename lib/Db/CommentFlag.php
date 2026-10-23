<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One member's flag on a comment. A moderator dismissing the flags sets dismissedAt;
 * dismissed flags no longer count towards hiding the comment.
 *
 * @method int getId()
 * @method int getCommentId()
 * @method void setCommentId(int $commentId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getDismissedAt()
 * @method void setDismissedAt(?\DateTime $dismissedAt)
 */
class CommentFlag extends Entity implements JsonSerializable {
	protected ?int $commentId = null;
	protected ?string $userId = null;
	protected ?string $reason = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $dismissedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('commentId', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('dismissedAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'commentId' => $this->commentId,
			'userId' => $this->userId,
			'reason' => $this->reason,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
			'dismissedAt' => $this->dismissedAt?->format('Y-m-d H:i:s'),
		];
	}
}
