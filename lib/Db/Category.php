<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getEarnRate()
 * @method void setEarnRate(string $earnRate)
 * @method string|null getIcon()
 * @method void setIcon(?string $icon)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getArchivedAt()
 * @method void setArchivedAt(?\DateTime $archivedAt)
 */
class Category extends Entity implements JsonSerializable {
	protected ?string $name = null;
	protected ?string $description = null;
	protected ?string $earnRate = null;
	protected ?string $icon = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $archivedAt = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('archivedAt', 'datetime');
		$this->addType('earnRate', 'string');
	}

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
			'earnRate' => (float)$this->earnRate,
			'icon' => $this->icon,
			'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
			'archived' => $this->archivedAt !== null,
			'archivedAt' => $this->archivedAt?->format('Y-m-d H:i:s'),
		];
	}
}
