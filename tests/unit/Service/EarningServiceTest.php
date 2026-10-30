<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Chapter\Electorate;
use OCA\TimeBank\Db\CategoryMapper;
use OCA\TimeBank\Db\Earning;
use OCA\TimeBank\Db\EarningMapper;
use OCA\TimeBank\Db\VoteMapper;
use OCA\TimeBank\Notification\ClaimNotifier;
use OCA\TimeBank\Service\ApproverPolicy;
use OCA\TimeBank\Service\BalanceService;
use OCA\TimeBank\Service\EarningService;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\NotAuthorizedException;
use OCA\TimeBank\Service\TransactionService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Nobody decides on their own claim, and a claim is decided once.
 */
final class EarningServiceTest extends TestCase {

	private EarningMapper&MockObject $earnings;
	private VoteMapper&MockObject $votes;
	private BalanceService&MockObject $balances;
	private ApproverPolicy&MockObject $policy;
	private ClaimNotifier&MockObject $notifier;
	private CategoryMapper&MockObject $categories;
	private IRootFolder&MockObject $root;
	private Electorate&MockObject $electorate;
	private EarningService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->earnings = $this->createMock(EarningMapper::class);
		$this->votes = $this->createMock(VoteMapper::class);
		$this->balances = $this->createMock(BalanceService::class);
		$this->policy = $this->createMock(ApproverPolicy::class);
		$this->policy->method('mayDecide')->willReturnCallback(fn (string $d) => $d !== 'nobody');
		$this->notifier = $this->createMock(ClaimNotifier::class);
		$this->categories = $this->createMock(CategoryMapper::class);
		$this->root = $this->createMock(IRootFolder::class);
		$this->service = new EarningService(
			$this->earnings,
			$this->categories,
			$this->votes,
			$this->balances,
			$this->createMock(TransactionService::class),
			$this->policy,
			$this->notifier,
			$this->root,
			$this->electorate = $this->createMock(Electorate::class),
		);
		// bob's state: ann, dan and eve vote. bob never does on his own claim.
		$this->electorate->method('forMember')->willReturnCallback(fn (string $member) => $member === 'bob' ? ['ann', 'dan', 'eve'] : ['nora', 'nate', 'nell']);
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

	public function testSomeoneWhoIsNotAnApproverForTheClaimantCannotDecide(): void {
		$this->claim('pending', 'bob');
		$this->balances->expects($this->never())->method('addHours');

		$this->expectException(NotAuthorizedException::class);
		$this->service->approveClaim(1, 'nobody');
	}

	public function testDecidingClearsTheNotification(): void {
		$this->claim('pending', 'bob');
		$this->notifier->expects($this->once())->method('decided');

		$this->service->approveClaim(1, 'admin');
	}

	public function testAChapterMemberOfTheClaimantsStateVotesWithoutBeingAnApprover(): void {
		$this->claim('voting', 'bob');
		$this->votes->method('findByEarning')->willReturn([]);

		$this->assertFalse($this->service->recordVote(1, 'ann', 'approve')['complete']);
	}

	public function testSomeoneOutsideTheClaimantsStateCannotVote(): void {
		$this->claim('voting', 'bob');
		$this->votes->expects($this->never())->method('insert');

		$this->expectException(NotAuthorizedException::class);
		$this->service->recordVote(1, 'nora', 'approve');
	}

	public function testEveryoneSeesPendingClaimsButOnlyApproversMayDecide(): void {
		$this->claim('pending', 'bob');
		$this->earnings->method('findByStatus')->willReturnCallback(fn (string $status) => $status === 'pending' ? [$this->earnings->find(1)] : []);
		$this->policy->method('capacity')->willReturnCallback(fn (string $d) => $d === 'fay' ? ['as' => 'facilitator', 'chapter' => 'oregon'] : null);

		$forAnn = $this->service->getPendingClaims('ann');
		$this->assertCount(1, $forAnn, 'a member sees it too');
		$this->assertFalse($forAnn[0]['canDecide']);
		$this->assertSame(['facilitator', 'oregon'], [$this->service->getPendingClaims('fay')[0]['decidingAs'], $this->service->getPendingClaims('fay')[0]['decidingChapter']]);
	}

	public function testEveryoneSeesVotesButOnlyTheStatesChapterMembersMayVoteOnce(): void {
		$this->claim('voting', 'bob');
		$this->earnings->method('findByStatus')->willReturnCallback(fn (string $status) => $status === 'voting' ? [$this->earnings->find(1)] : []);
		$vote = new \OCA\TimeBank\Db\Vote();
		$vote->setVoterId('dan');
		$vote->setVote('approve');
		$this->votes->method('findByEarning')->willReturn([$vote]);

		$this->assertTrue($this->service->getVotingClaims('ann')[0]['canVote']);
		$this->assertFalse($this->service->getVotingClaims('dan')[0]['canVote'], 'already voted');
		$this->assertFalse($this->service->getVotingClaims('nora')[0]['canVote'], 'another state');
		$this->assertFalse($this->service->getVotingClaims('bob')[0]['canVote'], 'the claimant');
		$this->assertSame(1, $this->service->getVotingClaims('nora')[0]['approveCount'], 'but everyone sees the tally');
	}

	/** bob's own files hold file 5 (readable) and file 6 (not readable); nothing else. */
	private function evidenceFiles(): void {
		$readable = $this->createMock(File::class);
		$readable->method('isReadable')->willReturn(true);
		$unreadable = $this->createMock(File::class);
		$unreadable->method('isReadable')->willReturn(false);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(fn (int $id) => match ($id) {
			5 => [$readable],
			6 => [$unreadable],
			default => [],
		});
		$this->root->method('getUserFolder')->with('bob')->willReturn($folder);
		$category = new \OCA\TimeBank\Db\Category();
		$category->setEarnRate('1.0');
		$this->categories->method('find')->willReturn($category);
		$this->earnings->method('insert')->willReturnArgument(0);
	}

	public function testEvidenceMustBeAFileTheClaimantCanRead(): void {
		$this->evidenceFiles();

		$claim = $this->service->submitClaim('bob', ['categoryId' => 1, 'hoursClaimed' => 1, 'evidenceFileId' => 5]);
		$this->assertSame(5, $claim->getEvidenceFileId());

		foreach ([6, 999] as $id) {
			try {
				$this->service->submitClaim('bob', ['categoryId' => 1, 'hoursClaimed' => 1, 'evidenceFileId' => $id]);
				$this->fail("file $id was accepted");
			} catch (InvalidStateException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testAClaimWithoutEvidenceDoesNotTouchFiles(): void {
		$this->evidenceFiles();
		$this->root->expects($this->never())->method('getUserFolder');

		$this->assertNull($this->service->submitClaim('bob', ['categoryId' => 1, 'hoursClaimed' => 1])->getEvidenceFileId());
	}
}
