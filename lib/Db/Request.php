<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getRequesterId()
 * @method void setRequesterId(string $requesterId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method int getCategoryId()
 * @method void setCategoryId(int $categoryId)
 * @method string getHoursBudget()
 * @method void setHoursBudget(string $hoursBudget)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getPriority()
 * @method void setPriority(string $priority)
 * @method \DateTime|null getDeadline()
 * @method void setDeadline(?\DateTime $deadline)
 * @method string|null getLocation()
 * @method void setLocation(?string $location)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class Request extends Entity implements JsonSerializable {
	protected ?string $requesterId = null;
	protected ?string $title = null;
	protected ?string $description = null;
	protected ?int $categoryId = null;
	protected ?string $hoursBudget = null;
	protected ?string $status = null;
	protected ?string $priority = null;
	protected ?\DateTime $deadline = null;
	protected ?string $location = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('categoryId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'requesterId' => $this->requesterId,
			'title' => $this->title,
			'description' => $this->description,
			'categoryId' => $this->categoryId,
			'hoursBudget' => (float)$this->hoursBudget,
			'status' => $this->status,
			'priority' => $this->priority,
			'deadline' => $this->deadline?->format('Y-m-d H:i:s'),
			'location' => $this->location,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
			'updatedAt' => $this->updatedAt?->format('Y-m-d H:i:s'),
		];
	}
}
