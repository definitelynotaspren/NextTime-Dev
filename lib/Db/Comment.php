<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getRequestId()
 * @method void setRequestId(int $requestId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getComment()
 * @method void setComment(string $comment)
 * @method int|null getParentId()
 * @method void setParentId(?int $parentId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Comment extends Entity implements JsonSerializable {
	protected ?int $requestId = null;
	protected ?string $userId = null;
	protected ?string $comment = null;
	protected ?int $parentId = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('requestId', 'integer');
		$this->addType('parentId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'requestId' => $this->requestId,
			'userId' => $this->userId,
			'comment' => $this->comment,
			'parentId' => $this->parentId,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
		];
	}
}
