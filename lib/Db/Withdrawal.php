<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One entry in the log of withdrawn help: a volunteer stepped back after the
 * requester had accepted them. Kept even though the offer itself is removed.
 *
 * @method int getId()
 * @method int getRequestId()
 * @method void setRequestId(int $requestId)
 * @method string getVolunteerId()
 * @method void setVolunteerId(string $volunteerId)
 * @method string getOfferStatus()
 * @method void setOfferStatus(string $offerStatus)
 * @method string getProposedHours()
 * @method void setProposedHours(string $proposedHours)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Withdrawal extends Entity implements JsonSerializable {
	protected ?int $requestId = null;
	protected ?string $volunteerId = null;
	protected ?string $offerStatus = null;
	protected ?string $proposedHours = null;
	protected ?string $reason = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('requestId', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'requestId' => $this->requestId,
			'volunteerId' => $this->volunteerId,
			'offerStatus' => $this->offerStatus,
			'proposedHours' => (float)$this->proposedHours,
			'reason' => $this->reason,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
		];
	}
}
