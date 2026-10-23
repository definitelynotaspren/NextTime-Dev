<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One line of the public governance log: who changed what, and why.
 *
 * @method int getId()
 * @method string getActorId()
 * @method void setActorId(string $actorId)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string|null getDetails()
 * @method void setDetails(?string $details)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class GovernanceEntry extends Entity implements JsonSerializable {
	protected ?string $actorId = null;
	protected ?string $action = null;
	protected ?string $subject = null;
	protected ?string $details = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		$details = $this->details === null ? null : json_decode($this->details, true);

		return [
			'id' => $this->id,
			'actorId' => $this->actorId,
			'action' => $this->action,
			'subject' => $this->subject,
			'details' => is_array($details) ? $details : null,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
		];
	}
}
