<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Db\CategoryMapper;
use OCA\TimeBank\Db\Earning;
use OCA\TimeBank\Db\EarningMapper;
use OCA\TimeBank\Db\VoteMapper;
use OCA\TimeBank\Service\BalanceService;
use OCA\TimeBank\Service\EarningService;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\NotAuthorizedException;
use OCA\TimeBank\Service\TransactionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Nobody decides on their own claim, and a claim is decided once.
 */
final class EarningServiceTest extends TestCase {

	private EarningMapper&MockObject $earnings;
	private VoteMapper&MockObject $votes;
	private BalanceService&MockObject $balances;
	private EarningService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->earnings = $this->createMock(EarningMapper::class);
		$this->votes = $this->createMock(VoteMapper::class);
		$this->balances = $this->createMock(BalanceService::class);
		$this->service = new EarningService(
			$this->earnings,
			$this->createMock(CategoryMapper::class),
			$this->votes,
			$this->balances,
			$this->createMock(TransactionService::class),
		);
		$this->earnings->method('update')->willReturnArgument(0);
	}

	private function claim(string $status, string $owner = 'bob'): Earning {
		$earning = new Earning();
		$earning->setId(1);
		$earning->setUserId($owner);
		$earning->setStatus($status);
		$earning->setActualHoursEarned('2.00');
		$this->earnings->method('find')->willReturn($earning);
		return $earning;
	}

	public function testNobodyApprovesRejectsOrSendsToVoteTheirOwnClaim(): void {
		$this->claim('pending', 'admin');
		$this->balances->expects($this->never())->method('addHours');

		foreach ([
			fn () => $this->service->approveClaim(1, 'admin'),
			fn () => $this->service->rejectClaim(1, 'admin', 'Not good enough'),
			fn () => $this->service->sendToVoting(1, 'admin'),
		] as $action) {
			try {
				$action();
				$this->fail('An admin must not decide on their own claim');
			} catch (NotAuthorizedException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testSomeoneElseCanApproveAndTheHoursAreCredited(): void {
		$claim = $this->claim('pending', 'bob');
		$this->balances->expects($this->once())->method('addHours')->with('bob', 2.0);

		$approved = $this->service->approveClaim(1, 'admin');
		$this->assertSame('approved', $approved->getStatus());
		$this->assertSame('admin', $claim->getApproverId());
	}

	public function testAClaimIsDecidedOnlyOnce(): void {
		$this->claim('approved');
		$this->balances->expects($this->never())->method('addHours');

		$this->expectException(InvalidStateException::class);
		$this->service->approveClaim(1, 'admin');
	}

	public function testAClaimantCannotVoteOnTheirOwnClaim(): void {
		$this->claim('voting', 'bob');
		$this->votes->expects($this->never())->method('insert');

		$this->expectException(NotAuthorizedException::class);
		$this->service->recordVote(1, 'bob', 'approve');
	}

	public function testVotesAreOnlyTakenWhileTheClaimIsInAVote(): void {
		$this->claim('pending', 'bob');
		$this->votes->expects($this->never())->method('insert');

		$this->expectException(InvalidStateException::class);
		$this->service->recordVote(1, 'carol', 'approve');
	}
}
