<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getBalance()
 * @method void setBalance(string $balance)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class Balance extends Entity implements JsonSerializable {
	protected ?string $userId = null;
	protected ?string $balance = null;
	protected ?\DateTime $updatedAt = null;

	public function jsonSerialize(): array {
		return [
			'userId' => $this->userId,
			'balance' => (float)$this->balance,
			'updatedAt' => $this->updatedAt?->format('Y-m-d H:i:s'),
		];
	}
}
