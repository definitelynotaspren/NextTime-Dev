<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string|null getFromUserId()
 * @method void setFromUserId(?string $fromUserId)
 * @method string|null getToUserId()
 * @method void setToUserId(?string $toUserId)
 * @method string getHours()
 * @method void setHours(string $hours)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method string getTransactionType()
 * @method void setTransactionType(string $transactionType)
 * @method int|null getReferenceId()
 * @method void setReferenceId(?int $referenceId)
 * @method string|null getReferenceType()
 * @method void setReferenceType(?string $referenceType)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Transaction extends Entity implements JsonSerializable {
	protected ?string $fromUserId = null;
	protected ?string $toUserId = null;
	protected ?string $hours = null;
	protected ?string $description = null;
	protected ?string $transactionType = null;
	protected ?int $referenceId = null;
	protected ?string $referenceType = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('referenceId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'fromUserId' => $this->fromUserId,
			'toUserId' => $this->toUserId,
			'hours' => (float)$this->hours,
			'description' => $this->description,
			'transactionType' => $this->transactionType,
			'referenceId' => $this->referenceId,
			'referenceType' => $this->referenceType,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
		];
	}
}
