<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A manual change to a member's balance. Small ones are applied by one admin; larger ones
 * stay pending until a second, different person approves.
 *
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getHours()
 * @method void setHours(string $hours)
 * @method string getReason()
 * @method void setReason(string $reason)
 * @method string getProposerId()
 * @method void setProposerId(string $proposerId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getApproverId()
 * @method void setApproverId(?string $approverId)
 * @method string|null getDecisionNote()
 * @method void setDecisionNote(?string $decisionNote)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getExpiresAt()
 * @method void setExpiresAt(\DateTime $expiresAt)
 * @method \DateTime|null getDecidedAt()
 * @method void setDecidedAt(?\DateTime $decidedAt)
 */
class Adjustment extends Entity implements JsonSerializable {
	protected ?string $userId = null;
	protected ?string $hours = null;
	protected ?string $reason = null;
	protected ?string $proposerId = null;
	protected ?string $status = null;
	protected ?string $approverId = null;
	protected ?string $decisionNote = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $expiresAt = null;
	protected ?\DateTime $decidedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('expiresAt', 'datetime');
		$this->addType('decidedAt', 'datetime');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'hours' => (float)$this->hours,
			'reason' => $this->reason,
			'proposerId' => $this->proposerId,
			'status' => $this->status,
			'approverId' => $this->approverId,
			'decisionNote' => $this->decisionNote,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
			'expiresAt' => $this->expiresAt?->format('Y-m-d H:i:s'),
			'decidedAt' => $this->decidedAt?->format('Y-m-d H:i:s'),
		];
	}
}
