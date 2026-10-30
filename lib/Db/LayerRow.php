<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One organizer-desk row (a need, offer, action, report or bill tag).
 *
 * data holds the layer's own fields as JSON. Fields are filtered by visibility
 * on read, so never serialize this entity directly to a client.
 *
 * @method int getId()
 * @method string getLayer()
 * @method void setLayer(string $layer)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string|null getChapter()
 * @method void setChapter(?string $chapter)
 * @method string|null getChapterOverride()
 * @method void setChapterOverride(?string $chapterOverride)
 * @method bool getPublish()
 * @method void setPublish(bool $publish)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getData()
 * @method void setData(string $data)
 * @method string|null getAssignedTo()
 * @method void setAssignedTo(?string $assignedTo)
 * @method string|null getAssignmentStatus()
 * @method void setAssignmentStatus(?string $assignmentStatus)
 * @method \DateTime|null getAssignedAt()
 * @method void setAssignedAt(?\DateTime $assignedAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class LayerRow extends Entity {
	protected ?string $layer = null;
	protected ?string $uid = null;
	protected ?string $chapter = null;
	protected ?string $chapterOverride = null;
	protected ?bool $publish = null;
	protected ?string $source = null;
	protected ?string $data = null;
	protected ?string $assignedTo = null;
	protected ?string $assignmentStatus = null;
	protected ?\DateTime $assignedAt = null;
	protected ?string $createdBy = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('publish', 'boolean');
		$this->addType('assignedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}
