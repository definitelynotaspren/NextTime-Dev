<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getEarningId()
 * @method void setEarningId(int $earningId)
 * @method string getVoterId()
 * @method void setVoterId(string $voterId)
 * @method string getVote()
 * @method void setVote(string $vote)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Vote extends Entity implements JsonSerializable {
	protected ?int $earningId = null;
	protected ?string $voterId = null;
	protected ?string $vote = null;
	protected ?string $comment = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('earningId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'earningId' => $this->earningId,
			'voterId' => $this->voterId,
			'vote' => $this->vote,
			'comment' => $this->comment,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
		];
	}
}
