<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Chapter\Chapter;
use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Chapter\NationalOrganizers;
use OCA\TimeBank\Db\Adjustment;
use OCA\TimeBank\Db\AdjustmentMapper;
use OCA\TimeBank\Db\AdjustmentVote;
use OCA\TimeBank\Db\AdjustmentVoteMapper;
use OCA\TimeBank\Schema\Viewer;
use OCA\TimeBank\Service\AdjustmentService;
use OCA\TimeBank\Service\ApproverPolicy;
use OCA\TimeBank\Service\BalanceService;
use OCA\TimeBank\Service\GovernanceLog;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\NotAuthorizedException;
use OCA\TimeBank\Service\TransactionService;
use OCA\TimeBank\Service\ViewerResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Admins (break-glass): small adjustments go through at once; larger ones need a second person.
 * Facilitators and national organizers (Decision 3 of the security plan): small ones need a second
 * facilitator or national organizer; larger ones go to a 14-day vote of the member's state.
 */
final class AdjustmentServiceTest extends TestCase {

	private AdjustmentMapper&MockObject $mapper;
	private BalanceService&MockObject $balances;
	private TransactionService&MockObject $transactions;
	private GovernanceLog&MockObject $log;
	private IDBConnection&MockObject $db;
	private AdjustmentService $service;
	/** @var array<string, string[]> chapters a user belongs to */
	private array $chapters = [];
	/** @var array<string, string[]> chapters a user facilitates (member and facilitator groups both) */
	private array $facilitates = [];
	/** @var list<string> */
	private array $nationals = [];
	/** @var list<AdjustmentVote> */
	private array $votes = [];
	/** The one adjustment the mocked mapper holds. */
	private ?Adjustment $current = null;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(AdjustmentMapper::class);
		$this->balances = $this->createMock(BalanceService::class);
		$this->transactions = $this->createMock(TransactionService::class);
		$this->log = $this->createMock(GovernanceLog::class);
		$this->db = $this->createMock(IDBConnection::class);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $u) => str_starts_with($u, 'admin'));
		$groups->method('search')->willReturn([]);
		$viewers = $this->createMock(ViewerResolver::class);
		$viewers->method('forUser')->willReturnCallback(fn (string $u) => new Viewer(
			$u,
			in_array($u, $this->nationals, true),
			($this->facilitates[$u] ?? []) !== [],
			$this->facilitates[$u] ?? [],
			($this->chapters[$u] ?? []) !== [] || ($this->facilitates[$u] ?? []) !== [],
		));
		$viewers->method('facilitatedChapters')->willReturnCallback(fn (string $u) => $this->facilitates[$u] ?? []);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(fn (string $u) => $u !== 'ghost');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);

		$directory = $this->createMock(ChapterDirectory::class);
		$directory->method('memberChapters')->willReturnCallback(fn (string $u) => $this->chapters[$u] ?? []);
		$directory->method('facilitatedChapters')->willReturnCallback(fn (string $u) => $this->facilitates[$u] ?? []);
		$directory->method('get')->willReturnCallback(fn (string $slug) => new Chapter($slug, $slug, 'mem-' . $slug, 'fac-' . $slug, null));
		$directory->method('usersOf')->willReturnCallback(function (string $gid) {
			$slug = substr($gid, 4);
			$byGroup = str_starts_with($gid, 'mem-') ? $this->chapters : $this->facilitates;
			return array_keys(array_filter($byGroup, fn (array $c) => in_array($slug, $c, true)));
		});
		$nationals = $this->createMock(NationalOrganizers::class);
		$nationals->method('isNationalOrganizer')->willReturnCallback(fn (string $u) => in_array($u, $this->nationals, true));
		$nationals->method('users')->willReturnCallback(fn () => $this->nationals);
		$votes = $this->createMock(AdjustmentVoteMapper::class);
		$votes->method('findByAdjustment')->willReturnCallback(fn (int $id) => array_values(array_filter($this->votes, fn (AdjustmentVote $v) => $v->getAdjustmentId() === $id)));
		$votes->method('insert')->willReturnCallback(function (AdjustmentVote $v) {
			$this->votes[] = $v;
			return $v;
		});
		$policy = new ApproverPolicy($directory, $groups, $nationals);
		$this->service = new AdjustmentService($this->mapper, $votes, $this->balances, $this->transactions, $this->log, $viewers, $policy, $directory, $nationals, $groups, $users, $config, $this->db);

		$this->mapper->method('insert')->willReturnCallback(function (Adjustment $a) {
			$a->setId(7);
			return $this->current = $a;
		});
		$this->mapper->method('find')->willReturnCallback(fn () => $this->current ?? throw new DoesNotExistException('none'));
		$this->mapper->method('findRecent')->willReturnCallback(fn () => $this->current === null ? [] : [$this->current]);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->balances->method('hasSufficientBalance')->willReturn(true);
	}

	private function pending(string $proposer = 'admin1', string $member = 'bob', string $hours = '10.00'): Adjustment {
		$a = new Adjustment();
		$a->setId(7);
		$a->setUserId($member);
		$a->setHours($hours);
		$a->setReason('Correcting a double-counted event');
		$a->setProposerId($proposer);
		$a->setStatus('pending');
		$a->setCreatedAt(new \DateTime());
		$a->setExpiresAt(new \DateTime('+14 days'));
		return $this->current = $a;
	}

	public function testASmallAdjustmentIsAppliedAtOnceByOneAdmin(): void {
		$this->balances->expects($this->once())->method('addHours')->with('bob', 3.0);
		$this->transactions->expects($this->once())->method('recordAdjustment')->with('bob', 3.0, $this->anything(), 'admin1', null);
		$this->db->expects($this->once())->method('commit');

		$a = $this->service->propose('admin1', 'bob', 3.0, 'Thanks for running the swap');
		$this->assertSame('approved', $a->getStatus());
	}

	public function testALargeAdjustmentWaitsForASecondPerson(): void {
		$this->balances->expects($this->never())->method('addHours');

		$a = $this->service->propose('admin1', 'bob', 10.0, 'Correcting a double-counted event');
		$this->assertSame('pending', $a->getStatus());
	}

	public function testNobodyAdjustsTheirOwnBalance(): void {
		$this->expectException(NotAuthorizedException::class);
		$this->service->propose('admin1', 'admin1', 1.0, 'A reason that is long enough');
	}

	public function testOnlyAdminsPropose(): void {
		$this->expectException(NotAuthorizedException::class);
		$this->service->propose('bob', 'carol', 1.0, 'A reason that is long enough');
	}

	public function testReasonsMembersAndSizesAreChecked(): void {
		foreach ([
			['bob', 1.0, 'short'],
			['ghost', 1.0, 'A reason that is long enough'],
			['bob', 0.0, 'A reason that is long enough'],
			['bob', 500.0, 'A reason that is long enough'],
		] as [$member, $hours, $reason]) {
			try {
				$this->service->propose('admin1', $member, $hours, $reason);
				$this->fail("Should refuse $member $hours $reason");
			} catch (InvalidStateException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testTheProposerCannotApproveTheirOwnProposal(): void {
		$this->pending('admin1');
		$this->balances->expects($this->never())->method('addHours');

		$this->expectException(NotAuthorizedException::class);
		$this->service->approve(7, 'admin1');
	}

	public function testAnotherAdminCanApproveAndTheLedgerNamesBoth(): void {
		$this->pending('admin1');
		$this->balances->expects($this->once())->method('addHours')->with('bob', 10.0);
		$this->transactions->expects($this->once())->method('recordAdjustment')->with('bob', 10.0, $this->anything(), 'admin1', 'admin2');

		$a = $this->service->approve(7, 'admin2');
		$this->assertSame('approved', $a->getStatus());
		$this->assertSame('admin2', $a->getApproverId());
	}

	public function testAFacilitatorOfTheMembersChapterCanApproveButNotAFacilitatorOfAnother(): void {
		$this->chapters = ['bob' => ['mt-hood'], 'fay' => ['mt-hood'], 'gus' => ['gresham']];
		$this->facilitates = ['fay' => ['mt-hood'], 'gus' => ['gresham']];
		$this->pending('admin1', 'bob');

		try {
			$this->service->approve(7, 'gus');
			$this->fail('A facilitator of another chapter must not approve');
		} catch (NotAuthorizedException) {
			$this->assertSame('approved', $this->service->approve(7, 'fay')->getStatus());
		}
	}

	public function testTheMemberCannotApproveAdjustmentsToThemselves(): void {
		$this->chapters = ['bob' => ['mt-hood']];
		$this->facilitates = ['bob' => ['mt-hood']];
		$this->pending('admin1', 'bob');

		$this->expectException(NotAuthorizedException::class);
		$this->service->approve(7, 'bob');
	}

	public function testAnAdjustmentThatWouldGoBelowZeroIsRefusedAndRolledBack(): void {
		$balances = $this->createMock(BalanceService::class);
		$balances->method('hasSufficientBalance')->willReturn(false);
		$service = new AdjustmentService(
			$this->mapper, $this->createMock(AdjustmentVoteMapper::class), $balances, $this->transactions, $this->log,
			$this->createMock(ViewerResolver::class), $this->createMock(ApproverPolicy::class), $this->createMock(ChapterDirectory::class),
			$this->createMock(NationalOrganizers::class), $this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class), $this->createMock(IAppConfig::class), $this->db,
		);
		$a = $this->pending('admin1', 'bob', '-10.00');

		$this->db->expects($this->once())->method('rollBack');
		$this->transactions->expects($this->never())->method('recordAdjustment');

		try {
			$ref = new \ReflectionMethod($service, 'apply');
			$ref->invoke($service, $a, 'admin2');
			$this->fail('Expected a refusal');
		} catch (InvalidStateException) {
			$this->assertSame('pending', $a->getStatus());
		}
	}

	public function testExpiredProposalsCannotBeApproved(): void {
		$a = $this->pending('admin1');
		$a->setExpiresAt(new \DateTime('-1 day'));

		try {
			$this->service->approve(7, 'admin2');
			$this->fail('An expired proposal must not be approved');
		} catch (InvalidStateException) {
			$this->assertSame('expired', $a->getStatus());
		}
	}

	public function testOnlyTheProposerWithdraws(): void {
		$this->pending('admin1');
		try {
			$this->service->withdraw(7, 'admin2');
			$this->fail('Someone else must not withdraw it');
		} catch (NotAuthorizedException) {
			$this->assertSame('withdrawn', $this->service->withdraw(7, 'admin1')->getStatus());
		}
	}

	// ---- facilitators and national organizers (Decision 3) -------------------

	/** Oregon: bob (member), ann, dan, eve (members), fay and fred (facilitators). Washington: carol, gus. */
	private function twoStates(): void {
		$this->chapters = [
			'bob' => ['oregon'], 'ann' => ['oregon'], 'dan' => ['oregon'], 'eve' => ['oregon'],
			'carol' => ['washington'],
		];
		$this->facilitates = ['fay' => ['oregon'], 'fred' => ['oregon'], 'gus' => ['washington']];
		$this->nationals = ['nora', 'nate'];
	}

	public function testAFacilitatorProposesOnlyForMembersOfTheirOwnState(): void {
		$this->twoStates();
		$this->balances->expects($this->never())->method('addHours');

		$this->assertSame('pending', $this->service->propose('fay', 'bob', 3.0, 'Ran the tool library all month')->getStatus());
		try {
			$this->service->propose('fay', 'carol', 3.0, 'Ran the tool library all month');
			$this->fail('An Oregon facilitator must not propose for a Washington member');
		} catch (NotAuthorizedException) {
			$this->addToAssertionCount(1);
		}
	}

	public function testANationalOrganizerProposesForAnyoneEvenWithNoState(): void {
		$this->twoStates();

		$this->assertSame('pending', $this->service->propose('nora', 'stranger', 3.0, 'Covered the hotline twice')->getStatus());
	}

	public function testASmallProposalNeedsASecondFacilitatorOfThatStateOrANationalOrganizer(): void {
		$this->twoStates();
		$this->service->propose('fay', 'bob', 3.0, 'Ran the tool library all month');

		foreach (['fay' => 'the proposer', 'gus' => 'a facilitator of another state', 'ann' => 'a chapter member'] as $who => $why) {
			try {
				$this->service->approve(7, $who);
				$this->fail("$why must not approve");
			} catch (NotAuthorizedException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->transactions->expects($this->once())->method('recordAdjustment')->with('bob', 3.0, $this->anything(), 'fay', 'fred');
		$this->assertSame('approved', $this->service->approve(7, 'fred')->getStatus());
	}

	public function testALargeProposalOpensAFourteenDayVoteInsteadOfASecondPerson(): void {
		$this->twoStates();
		$a = $this->service->propose('fay', 'bob', 10.0, 'Organised the winter clothing drive');

		$this->assertSame('voting', $a->getStatus());
		$this->assertEqualsWithDelta((new \DateTime('+14 days'))->getTimestamp(), $a->getExpiresAt()->getTimestamp(), 5);
		try {
			$this->service->approve(7, 'fred');
			$this->fail('A vote is not decided by one person');
		} catch (InvalidStateException) {
			$this->addToAssertionCount(1);
		}
	}

	public function testOnlyTheStatesChapterMembersVoteAndNeitherTheProposerNorTheMember(): void {
		$this->twoStates();
		$this->service->propose('fay', 'bob', 10.0, 'Organised the winter clothing drive');

		foreach (['fay' => 'the proposer', 'bob' => 'the member', 'carol' => 'another state\'s member', 'nora' => 'a national organizer'] as $who => $why) {
			try {
				$this->service->vote(7, $who, true);
				$this->fail("$why must not vote");
			} catch (NotAuthorizedException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->service->vote(7, 'ann', true);
		try {
			$this->service->vote(7, 'ann', false);
			$this->fail('One vote per person');
		} catch (InvalidStateException) {
			$this->assertCount(1, $this->votes);
		}
	}

	public function testThreeVotesWithMoreApprovalsApplyItOnceEveryoneHasVoted(): void {
		$this->twoStates();
		// Electorate: ann, dan, eve and fred (fay proposed, bob is the member).
		$this->service->propose('fay', 'bob', 10.0, 'Organised the winter clothing drive');
		$this->transactions->expects($this->once())->method('recordAdjustment')
			->with('bob', 10.0, $this->anything(), 'fay', $this->stringContains('community vote'));

		$this->service->vote(7, 'ann', true);
		$this->service->vote(7, 'dan', true);
		$this->service->vote(7, 'eve', false);
		$this->assertSame('voting', $this->current?->getStatus(), 'fred may still vote');

		$this->assertSame('approved', $this->service->vote(7, 'fred', true)->getStatus());
	}

	public function testATieOrMoreRejectionsRejects(): void {
		$this->twoStates();
		$this->service->propose('fay', 'bob', 10.0, 'Organised the winter clothing drive');
		$this->balances->expects($this->never())->method('addHours');

		$this->service->vote(7, 'ann', true);
		$this->service->vote(7, 'dan', true);
		$this->service->vote(7, 'eve', false);

		$this->assertSame('rejected', $this->service->vote(7, 'fred', false)->getStatus());
	}

	public function testOnDayFourteenThreeVotesDecideAndFewerExpire(): void {
		$this->twoStates();
		$this->service->propose('fay', 'bob', 10.0, 'Organised the winter clothing drive');
		$this->service->vote(7, 'ann', true);
		$this->service->vote(7, 'dan', true);
		$this->service->vote(7, 'eve', false);
		$this->current?->setExpiresAt(new \DateTime('-1 minute'));
		$this->balances->expects($this->once())->method('addHours')->with('bob', 10.0);

		$this->service->listFor('nora');
		$this->assertSame('approved', $this->current?->getStatus());

		$this->votes = [];
		$this->service->propose('fay', 'bob', 12.0, 'Organised the spring clothing drive');
		$this->service->vote(7, 'ann', true);
		$this->service->vote(7, 'dan', true);
		$this->current?->setExpiresAt(new \DateTime('-1 minute'));
		$this->service->listFor('nora');
		$this->assertSame('expired', $this->current?->getStatus(), 'two votes are not enough');
	}

	public function testMembersWithNoStateAreVotedOnByNationalOrganizers(): void {
		$this->twoStates();
		$this->nationals = ['nora', 'nate', 'nell', 'ned'];
		$this->service->propose('nora', 'stranger', 10.0, 'Covered the hotline all winter');

		try {
			$this->service->vote(7, 'ann', true);
			$this->fail('A chapter member does not vote on a member with no state');
		} catch (NotAuthorizedException) {
			$this->addToAssertionCount(1);
		}
		$this->service->vote(7, 'nate', true);
		$this->service->vote(7, 'nell', true);
		$this->assertSame('approved', $this->service->vote(7, 'ned', true)->getStatus());
	}

	public function testTheProposerCanWithdrawAnOpenVote(): void {
		$this->twoStates();
		$this->service->propose('fay', 'bob', 10.0, 'Organised the winter clothing drive');

		$this->assertSame('withdrawn', $this->service->withdraw(7, 'fay')->getStatus());
	}

	public function testTheListIsScopedToTheViewersStates(): void {
		$this->twoStates();
		$this->service->propose('fay', 'bob', 10.0, 'Organised the winter clothing drive');

		$this->assertCount(1, $this->service->listFor('fred'), 'a facilitator of the state');
		$this->assertCount(1, $this->service->listFor('nora'), 'a national organizer sees all');
		$this->assertCount(0, $this->service->listFor('gus'), 'a facilitator of another state sees none');
		$this->assertCount(0, $this->service->listFor('carol'), 'a member of another state sees none');

		$forAnn = $this->service->listFor('ann');
		$this->assertCount(1, $forAnn, 'a chapter member sees the vote open to them');
		$this->assertTrue($forAnn[0]['canVote']);
		$this->assertSame(4, $forAnn[0]['vote']['eligible']);
	}

	public function testOnlyFacilitatorsNationalOrganizersAndAdminsMayPropose(): void {
		$this->twoStates();

		$this->assertTrue($this->service->mayPropose('fay'));
		$this->assertTrue($this->service->mayPropose('nora'));
		$this->assertTrue($this->service->mayPropose('admin1'));
		$this->assertFalse($this->service->mayPropose('ann'));
	}
}
