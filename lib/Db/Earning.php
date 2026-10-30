<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCategoryId()
 * @method void setCategoryId(int $categoryId)
 * @method string getHoursClaimed()
 * @method void setHoursClaimed(string $hoursClaimed)
 * @method string getActualHoursEarned()
 * @method void setActualHoursEarned(string $actualHoursEarned)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method int|null getEvidenceFileId()
 * @method void setEvidenceFileId(?int $evidenceFileId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getApproverId()
 * @method void setApproverId(?string $approverId)
 * @method string|null getRejectionReason()
 * @method void setRejectionReason(?string $rejectionReason)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getApprovedAt()
 * @method void setApprovedAt(?\DateTime $approvedAt)
 * @method int getNotifiedStage()
 * @method void setNotifiedStage(int $notifiedStage)
 * @method string|null getSourceRef()
 * @method void setSourceRef(?string $sourceRef)
 * @method \DateTime|null getApprovedBeforeAt()
 * @method void setApprovedBeforeAt(?\DateTime $approvedBeforeAt)
 */
class Earning extends Entity implements JsonSerializable {
	protected ?string $userId = null;
	protected ?int $categoryId = null;
	protected ?string $hoursClaimed = null;
	protected ?string $actualHoursEarned = null;
	protected ?string $description = null;
	protected ?int $evidenceFileId = null;
	protected ?string $status = null;
	protected ?string $approverId = null;
	protected ?string $rejectionReason = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $approvedAt = null;
	protected int $notifiedStage = 0;
	protected ?string $sourceRef = null;
	protected ?\DateTime $approvedBeforeAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('approvedAt', 'datetime');
		$this->addType('categoryId', 'integer');
		$this->addType('evidenceFileId', 'integer');
		$this->addType('notifiedStage', 'integer');
		$this->addType('approvedBeforeAt', 'datetime');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'categoryId' => $this->categoryId,
			'hoursClaimed' => (float)$this->hoursClaimed,
			'actualHoursEarned' => (float)$this->actualHoursEarned,
			'description' => $this->description,
			'evidenceFileId' => $this->evidenceFileId,
			'status' => $this->status,
			'approverId' => $this->approverId,
			'rejectionReason' => $this->rejectionReason,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
			'approvedAt' => $this->approvedAt?->format('Y-m-d H:i:s'),
			// set on a claim imported from another system where it had already been approved
			'approvedBefore' => $this->approvedBeforeAt?->format('Y-m-d H:i:s'),
		];
	}
}
