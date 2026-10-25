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
 * @method float|null getLatitude()
 * @method void setLatitude(?float $latitude)
 * @method float|null getLongitude()
 * @method void setLongitude(?float $longitude)
 * @method string|null getZip()
 * @method void setZip(?string $zip)
 * @method string|null getChapter()
 * @method void setChapter(?string $chapter)
 * @method string|null getChapterOverride()
 * @method void setChapterOverride(?string $chapterOverride)
 * @method string getMode()
 * @method void setMode(string $mode)
 * @method int|null getResourceFileId()
 * @method void setResourceFileId(?int $resourceFileId)
 * @method string getReviewStatus()
 * @method void setReviewStatus(string $reviewStatus)
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
	protected ?float $latitude = null;
	protected ?float $longitude = null;
	protected ?string $zip = null;
	protected ?string $chapter = null;
	protected ?string $chapterOverride = null;
	protected ?string $mode = 'in_person';
	protected ?int $resourceFileId = null;
	protected ?string $reviewStatus = 'published';
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('deadline', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('categoryId', 'integer');
		$this->addType('latitude', 'float');
		$this->addType('longitude', 'float');
		$this->addType('resourceFileId', 'integer');
	}

	/**
	 * Everything but the exact location (address text and point), which only some people may see.
	 * RequestPresenter adds it for them; nothing should send a request out without going through it.
	 *
	 * @return array<string, mixed>
	 */
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
			'zip' => $this->zip,
			'mode' => $this->mode,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
			'updatedAt' => $this->updatedAt?->format('Y-m-d H:i:s'),
		];
	}
}
