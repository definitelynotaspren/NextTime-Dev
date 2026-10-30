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
 * @method string|null getAgreedHours()
 * @method void setAgreedHours(?string $agreedHours)
 * @method string|null getMessage()
 * @method void setMessage(?string $message)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method string|null getSourceRef()
 * @method void setSourceRef(?string $sourceRef)
 */
class Volunteer extends Entity implements JsonSerializable {
	protected ?int $requestId = null;
	protected ?string $volunteerId = null;
	protected ?string $proposedHours = null;
	protected ?string $agreedHours = null;
	protected ?string $message = null;
	protected ?string $status = null;
	protected ?\DateTime $createdAt = null;
	protected ?string $sourceRef = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('requestId', 'integer');
	}

	/**
	 * What this person is credited for: the hours agreed on acceptance, else the hours they offered.
	 */
	public function effectiveHours(): float {
		return (float)($this->agreedHours ?? $this->proposedHours);
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'requestId' => $this->requestId,
			'volunteerId' => $this->volunteerId,
			'proposedHours' => (float)$this->proposedHours,
			'agreedHours' => $this->agreedHours === null ? null : (float)$this->agreedHours,
			'hours' => $this->effectiveHours(),
			'message' => $this->message,
			'status' => $this->status,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
		];
	}
}
