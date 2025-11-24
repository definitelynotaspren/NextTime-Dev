<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\Balance;
use OCA\TimeBank\Db\BalanceMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class BalanceService {

	private BalanceMapper $balanceMapper;

	public function __construct(BalanceMapper $balanceMapper) {
		$this->balanceMapper = $balanceMapper;
	}

	public function getBalance(string $userId): Balance {
		try {
			return $this->balanceMapper->findByUserId($userId);
		} catch (DoesNotExistException $e) {
			$balance = new Balance();
			$balance->setUserId($userId);
			$balance->setBalance('0.00');
			$balance->setUpdatedAt(new \DateTime());
			return $this->balanceMapper->insertBalance($balance);
		}
	}

	public function addHours(string $userId, float $hours): Balance {
		$balance = $this->getBalance($userId);
		$newBalance = (float)$balance->getBalance() + $hours;
		$balance->setBalance((string)$newBalance);
		$balance->setUpdatedAt(new \DateTime());

		return $this->balanceMapper->updateBalance($balance);
	}

	public function deductHours(string $userId, float $hours): Balance {
		$balance = $this->getBalance($userId);
		$newBalance = (float)$balance->getBalance() - $hours;

		if ($newBalance < 0) {
			throw new \Exception('Insufficient balance');
		}

		$balance->setBalance((string)$newBalance);
		$balance->setUpdatedAt(new \DateTime());

		return $this->balanceMapper->updateBalance($balance);
	}

	public function hasSufficientBalance(string $userId, float $hours): bool {
		$balance = $this->getBalance($userId);
		return (float)$balance->getBalance() >= $hours;
	}

	/**
	 * @return Balance[]
	 */
	public function getAllBalances(int $limit = 100, int $offset = 0): array {
		return $this->balanceMapper->findAll($limit, $offset);
	}
}
