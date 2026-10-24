<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One Nextcloud Forms submission that NextTime has looked at.
 *
 * @method int getId()
 * @method int getFormId()
 * @method void setFormId(int $formId)
 * @method int getSubmissionId()
 * @method void setSubmissionId(int $submissionId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getLayer()
 * @method void setLayer(string $layer)
 * @method string|null getUid()
 * @method void setUid(?string $uid)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class IntakeLog extends Entity implements JsonSerializable {
	protected ?int $formId = null;
	protected ?int $submissionId = null;
	protected ?string $status = null;
	protected ?string $layer = null;
	protected ?string $uid = null;
	protected ?string $reason = null;
	protected ?\DateTime $createdAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('formId', 'integer');
		$this->addType('submissionId', 'integer');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'formId' => $this->formId,
			'submissionId' => $this->submissionId,
			'status' => $this->status,
			'layer' => $this->layer,
			'uid' => $this->uid,
			'reason' => $this->reason,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
		];
	}
}
