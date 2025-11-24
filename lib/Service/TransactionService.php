<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\Earning;
use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\Transaction;
use OCA\TimeBank\Db\TransactionMapper;

class TransactionService {

	private TransactionMapper $transactionMapper;

	public function __construct(TransactionMapper $transactionMapper) {
		$this->transactionMapper = $transactionMapper;
	}

	public function recordEarning(Earning $earning): Transaction {
		$transaction = new Transaction();
		$transaction->setFromUserId(null);
		$transaction->setToUserId($earning->getUserId());
		$transaction->setHours($earning->getActualHoursEarned());
		$transaction->setDescription('Earned: ' . substr($earning->getDescription(), 0, 450));
		$transaction->setTransactionType('earned');
		$transaction->setReferenceId($earning->getId());
		$transaction->setReferenceType('earning');
		$transaction->setCreatedAt(new \DateTime());

		return $this->transactionMapper->insert($transaction);
	}

	public function recordSpending(Request $request, string $spenderId, string $providerId, float $hours): Transaction {
		$transaction = new Transaction();
		$transaction->setFromUserId($spenderId);
		$transaction->setToUserId($providerId);
		$transaction->setHours((string)$hours);
		$transaction->setDescription('Request: ' . substr($request->getTitle(), 0, 450));
		$transaction->setTransactionType('spent');
		$transaction->setReferenceId($request->getId());
		$transaction->setReferenceType('request');
		$transaction->setCreatedAt(new \DateTime());

		return $this->transactionMapper->insert($transaction);
	}

	public function recordAdjustment(string $userId, float $hours, string $reason, string $adminUserId): Transaction {
		$transaction = new Transaction();

		if ($hours > 0) {
			$transaction->setFromUserId(null);
			$transaction->setToUserId($userId);
		} else {
			$transaction->setFromUserId($userId);
			$transaction->setToUserId(null);
		}

		$transaction->setHours((string)abs($hours));
		$transaction->setDescription('Admin adjustment by ' . $adminUserId . ': ' . substr($reason, 0, 400));
		$transaction->setTransactionType('adjusted');
		$transaction->setCreatedAt(new \DateTime());

		return $this->transactionMapper->insert($transaction);
	}

	/**
	 * @return array{transactions: array<array-key, mixed>, total: int, limit: int, offset: int}
	 */
	public function getPublicLedger(int $limit = 50, int $offset = 0): array {
		$transactions = $this->transactionMapper->findAll($limit, $offset);
		$total = $this->transactionMapper->count();

		return [
			'transactions' => array_map(fn($t) => $t->jsonSerialize(), $transactions),
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * @return array{transactions: array<array-key, mixed>, total: int, limit: int, offset: int}
	 */
	public function getUserTransactions(string $userId, int $limit = 50, int $offset = 0): array {
		$transactions = $this->transactionMapper->findByUser($userId, $limit, $offset);
		$total = $this->transactionMapper->countByUser($userId);

		return [
			'transactions' => array_map(fn($t) => $t->jsonSerialize(), $transactions),
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
		];
	}
}
