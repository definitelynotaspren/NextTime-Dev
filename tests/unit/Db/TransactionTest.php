<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Db;

use OCA\TimeBank\Db\Transaction;
use PHPUnit\Framework\TestCase;

class TransactionTest extends TestCase {

	private Transaction $transaction;

	protected function setUp(): void {
		parent::setUp();
		$this->transaction = new Transaction();
	}

	public function testJsonSerialize(): void {
		$this->transaction->setId(1);
		$this->transaction->setFromUserId('user1');
		$this->transaction->setToUserId('user2');
		$this->transaction->setHours('5.50');
		$this->transaction->setDescription('Payment for gardening work');
		$this->transaction->setTransactionType('transfer');
		$this->transaction->setReferenceId(123);
		$this->transaction->setReferenceType('earning');
		$this->transaction->setCreatedAt(new \DateTime('2024-01-15 10:30:00'));

		$json = $this->transaction->jsonSerialize();

		$this->assertEquals(1, $json['id']);
		$this->assertEquals('user1', $json['fromUserId']);
		$this->assertEquals('user2', $json['toUserId']);
		$this->assertEquals(5.50, $json['hours']);
		$this->assertEquals('Payment for gardening work', $json['description']);
		$this->assertEquals('transfer', $json['transactionType']);
		$this->assertEquals(123, $json['referenceId']);
		$this->assertEquals('earning', $json['referenceType']);
		$this->assertEquals('2024-01-15 10:30:00', $json['createdAt']);
	}

	public function testHoursConvertedToFloat(): void {
		$this->transaction->setHours('10.75');
		$json = $this->transaction->jsonSerialize();

		$this->assertIsFloat($json['hours']);
		$this->assertEquals(10.75, $json['hours']);
	}

	public function testNullableFields(): void {
		$this->transaction->setFromUserId(null);
		$this->transaction->setToUserId(null);
		$this->transaction->setReferenceId(null);
		$this->transaction->setReferenceType(null);

		$json = $this->transaction->jsonSerialize();

		$this->assertNull($json['fromUserId']);
		$this->assertNull($json['toUserId']);
		$this->assertNull($json['referenceId']);
		$this->assertNull($json['referenceType']);
	}

	public function testGettersAndSetters(): void {
		$this->transaction->setFromUserId('alice');
		$this->assertEquals('alice', $this->transaction->getFromUserId());

		$this->transaction->setToUserId('bob');
		$this->assertEquals('bob', $this->transaction->getToUserId());

		$this->transaction->setHours('15.25');
		$this->assertEquals('15.25', $this->transaction->getHours());

		$this->transaction->setDescription('Test transaction');
		$this->assertEquals('Test transaction', $this->transaction->getDescription());

		$this->transaction->setTransactionType('earning');
		$this->assertEquals('earning', $this->transaction->getTransactionType());
	}
}
