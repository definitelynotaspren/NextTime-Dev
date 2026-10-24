<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Chapter\Chapter;
use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Db\Adjustment;
use OCA\TimeBank\Db\AdjustmentMapper;
use OCA\TimeBank\Schema\Viewer;
use OCA\TimeBank\Service\AdjustmentService;
use OCA\TimeBank\Service\ApproverPolicy;
use OCA\TimeBank\Service\BalanceService;
use OCA\TimeBank\Service\GovernanceLog;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\NotAuthorizedException;
use OCA\TimeBank\Service\TransactionService;
use OCA\TimeBank\Service\ViewerResolver;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Small adjustments go through at once; larger ones need a second, different person.
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

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(AdjustmentMapper::class);
		$this->balances = $this->createMock(BalanceService::class);
		$this->transactions = $this->createMock(TransactionService::class);
		$this->log = $this->createMock(GovernanceLog::class);
		$this->db = $this->createMock(IDBConnection::class);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $u) => str_starts_with($u, 'admin'));
		$viewers = $this->createMock(ViewerResolver::class);
		$viewers->method('forUser')->willReturnCallback(fn (string $u) => new Viewer($u, false, ($this->facilitates[$u] ?? []) !== [], $this->facilitates[$u] ?? []));
		$viewers->method('memberChapters')->willReturnCallback(fn (string $u) => $this->chapters[$u] ?? []);
		$viewers->method('facilitatedChapters')->willReturnCallback(fn (string $u) => $this->facilitates[$u] ?? []);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(fn (string $u) => $u !== 'ghost');
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnArgument(2);

		$directory = $this->createMock(ChapterDirectory::class);
		$directory->method('memberChapters')->willReturnCallback(fn (string $u) => $this->chapters[$u] ?? []);
		$directory->method('facilitatedChapters')->willReturnCallback(fn (string $u) => $this->facilitates[$u] ?? []);
		$directory->method('get')->willReturnCallback(fn (string $slug) => new Chapter($slug, $slug, null, 'fac-' . $slug, null));
		$directory->method('usersOf')->willReturnCallback(function (string $gid) {
			$slug = substr($gid, 4);
			return array_keys(array_filter($this->facilitates, fn (array $c) => in_array($slug, $c, true)));
		});
		$policy = new ApproverPolicy($directory, $groups, $config);
		$this->service = new AdjustmentService($this->mapper, $this->balances, $this->transactions, $this->log, $viewers, $policy, $groups, $users, $config, $this->db);

		$this->mapper->method('insert')->willReturnCallback(function (Adjustment $a) {
			$a->setId(7);
			return $a;
		});
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
		$this->mapper->method('find')->willReturn($a);
		return $a;
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
			$this->mapper, $balances, $this->transactions, $this->log,
			$this->createMock(ViewerResolver::class), $this->createMock(ApproverPolicy::class), $this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class), $this->createMock(IConfig::class), $this->db,
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
}
