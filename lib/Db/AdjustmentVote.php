<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One member's vote on a large adjustment proposed by a facilitator or national organizer.
 * Votes are public, like the adjustment itself. One per person, enforced by a unique index.
 *
 * @method int getId()
 * @method int getAdjustmentId()
 * @method void setAdjustmentId(int $adjustmentId)
 * @method string getVoterId()
 * @method void setVoterId(string $voterId)
 * @method string getVote()
 * @method void setVote(string $vote)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class AdjustmentVote extends Entity implements JsonSerializable {
	protected ?int $adjustmentId = null;
	protected ?string $voterId = null;
	protected ?string $vote = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('adjustmentId', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'voterId' => $this->voterId,
			'vote' => $this->vote,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
		];
	}
}
