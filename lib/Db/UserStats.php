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
 * @method int getCompletedRequests()
 * @method void setCompletedRequests(int $completedRequests)
 * @method string getTotalHoursProvided()
 * @method void setTotalHoursProvided(string $totalHoursProvided)
 * @method \DateTime|null getLastActive()
 * @method void setLastActive(?\DateTime $lastActive)
 */
class UserStats extends Entity implements JsonSerializable {
	protected ?string $userId = null;
	protected ?int $categoryId = null;
	protected ?int $completedRequests = null;
	protected ?string $totalHoursProvided = null;
	protected ?\DateTime $lastActive = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('categoryId', 'integer');
		$this->addType('completedRequests', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'categoryId' => $this->categoryId,
			'completedRequests' => $this->completedRequests,
			'totalHoursProvided' => (float)$this->totalHoursProvided,
			'lastActive' => $this->lastActive?->format('Y-m-d H:i:s'),
		];
	}
}
