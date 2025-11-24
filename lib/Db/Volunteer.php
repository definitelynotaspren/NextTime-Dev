<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getRequestId()
 * @method void setRequestId(int $requestId)
 * @method string getVolunteerId()
 * @method void setVolunteerId(string $volunteerId)
 * @method string getProposedHours()
 * @method void setProposedHours(string $proposedHours)
 * @method string|null getMessage()
 * @method void setMessage(?string $message)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Volunteer extends Entity implements JsonSerializable {
	protected ?int $requestId = null;
	protected ?string $volunteerId = null;
	protected ?string $proposedHours = null;
	protected ?string $message = null;
	protected ?string $status = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('requestId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'requestId' => $this->requestId,
			'volunteerId' => $this->volunteerId,
			'proposedHours' => (float)$this->proposedHours,
			'message' => $this->message,
			'status' => $this->status,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
		];
	}
}
