<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Import;

use OCA\TimeBank\Db\Category;
use OCA\TimeBank\Db\CategoryMapper;
use OCA\TimeBank\Db\Earning;
use OCA\TimeBank\Db\EarningMapper;
use OCA\TimeBank\Db\Import;
use OCA\TimeBank\Db\ImportMapper;
use OCA\TimeBank\Db\ImportStagedRow;
use OCA\TimeBank\Db\ImportStagedRowMapper;
use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Db\Volunteer;
use OCA\TimeBank\Db\VolunteerMapper;
use OCA\TimeBank\Import\ImportPlanner;
use OCA\TimeBank\Import\ImportService;
use OCA\TimeBank\Import\Source;
use OCA\TimeBank\Import\SourceConnector;
use OCA\TimeBank\Service\GovernanceLog;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\NotAuthorizedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The two-admin import: preview writes nothing, staging holds rows and logs only totals, a different
 * admin finalizes (all or none), the same admin cannot, and nothing carries a connection detail.
 */
final class ImportServiceTest extends TestCase {

	private const PASSWORD = 'hunter2-secret';

	/** @var array<int, Import> */
	private array $store = [];
	/** @var list<ImportStagedRow> */
	private array $held = [];
	/** @var list<Request> */
	private array $newRequests = [];
	/** @var list<Volunteer> */
	private array $newOffers = [];
	/** @var list<Earning> */
	private array $newClaims = [];
	/** @var list<array{0: string, 1: string, 2: ?string, 3: array<string, mixed>}> */
	private array $logged = [];
	/** @var list<string> */
	private array $accounts = ['ana', 'ben', 'root', 'boss'];
	/** @var list<string> */
	private array $admins = ['root', 'boss'];
	/** @var array<string, array<string, true>> */
	private array $refsAlreadyHere = ['volunteer' => [], 'earning' => []];
	private bool $claimInsertFails = false;
	private bool $stagingInsertFails = false;
	private bool $deleteFails = false;
	/** @var list<array<string, mixed>> */
	private array $sourceRequests = [];
	/** @var list<array<string, mixed>> */
	private array $sourceOffers = [];
	/** @var list<array<string, mixed>> */
	private array $sourceClaims = [];
	private IDBConnection&MockObject $db;
	private ImportService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		$this->held = [];
		$this->newRequests = [];
		$this->newOffers = [];
		$this->newClaims = [];
		$this->logged = [];
		$this->accounts = ['ana', 'ben', 'root', 'boss'];
		$this->admins = ['root', 'boss'];
		$this->refsAlreadyHere = ['volunteer' => [], 'earning' => []];
		$this->claimInsertFails = false;
		$this->stagingInsertFails = false;
		$this->deleteFails = false;
		$this->sourceRequests = [
			['id' => 1, 'requester_id' => 'ana', 'title' => 'Homework help', 'description' => 'Fractions.', 'category_id' => 1, 'hours_budget' => '2.00', 'status' => 'open', 'zip' => '97055', 'created_at' => '2026-10-01 09:00:00'],
			['id' => 2, 'requester_id' => 'ben', 'title' => 'Fix a laptop', 'description' => 'Slow.', 'category_id' => 2, 'hours_budget' => '1.50', 'status' => 'completed', 'zip' => '97030', 'created_at' => '2026-10-02 09:00:00'],
		];
		$this->sourceOffers = [
			['id' => 10, 'request_id' => 1, 'volunteer_id' => 'ben', 'proposed_hours' => '3.00', 'message' => 'I can', 'status' => 'accepted', 'created_at' => '2026-10-01 10:00:00'],
		];
		$this->sourceClaims = [
			['id' => 20, 'user_id' => 'ben', 'category_id' => 1, 'hours_claimed' => '3.00', 'actual_hours_earned' => '3.00', 'description' => 'Tutoring', 'status' => 'approved', 'created_at' => '2026-10-03 09:00:00', 'approved_at' => '2026-10-04 09:00:00'],
			['id' => 21, 'user_id' => 'ana', 'category_id' => 2, 'hours_claimed' => '1.00', 'actual_hours_earned' => '1.50', 'description' => 'Setup', 'status' => 'pending', 'created_at' => '2026-10-05 09:00:00'],
		];

		$imports = $this->createMock(ImportMapper::class);
		$imports->method('find')->willReturnCallback(fn (int $id): Import => $this->store[$id] ?? throw new DoesNotExistException('no import'));
		$imports->method('insert')->willReturnCallback(function (Import $import): Import {
			$import->setId(count($this->store) + 1);
			$this->store[$import->getId()] = $import;

			return $import;
		});
		$imports->method('update')->willReturnCallback(function (Import $import): Import {
			$this->store[$import->getId()] = $import;

			return $import;
		});
		$imports->method('findStaged')->willReturnCallback(fn (): array => array_values(array_filter($this->store, static fn (Import $i): bool => $i->getStatus() === Import::STAGED)));
		$imports->method('findRecent')->willReturnCallback(fn (): array => array_values($this->store));
		$imports->method('findStagedExpiredBefore')->willReturnCallback(fn (\DateTime $now): array => array_values(array_filter($this->store, static fn (Import $i): bool => $i->getStatus() === Import::STAGED && $i->getExpiresAt() < $now)));

		$staged = $this->createMock(ImportStagedRowMapper::class);
		$staged->method('insert')->willReturnCallback(function (ImportStagedRow $row): ImportStagedRow {
			if ($this->stagingInsertFails) {
				throw new \RuntimeException('disk full');
			}
			$row->setId(count($this->held) + 1);
			$this->held[] = $row;

			return $row;
		});
		$staged->method('findByImport')->willReturnCallback(fn (int $id): array => array_values(array_filter($this->held, static fn (ImportStagedRow $r): bool => $r->getImportId() === $id)));
		$staged->method('deleteByImport')->willReturnCallback(function (int $id): void {
			if ($this->deleteFails) {
				throw new \RuntimeException('locked');
			}
			$this->held = array_values(array_filter($this->held, static fn (ImportStagedRow $r): bool => $r->getImportId() !== $id));
		});

		$requests = $this->createMock(RequestMapper::class);
		$requests->method('insert')->willReturnCallback(function (Request $request): Request {
			$request->setId(100 + count($this->newRequests));
			$this->newRequests[] = $request;

			return $request;
		});
		$requests->method('findBySourceRef')->willReturn(null);
		$requests->method('sourceRefsStartingWith')->willReturn([]);

		$volunteers = $this->createMock(VolunteerMapper::class);
		$volunteers->method('insert')->willReturnCallback(function (Volunteer $offer): Volunteer {
			$this->newOffers[] = $offer;

			return $offer;
		});
		$volunteers->method('sourceRefsStartingWith')->willReturnCallback(fn (): array => $this->refsAlreadyHere['volunteer']);
		$volunteers->method('findByRequestAndVolunteer')->willThrowException(new DoesNotExistException('no offer'));

		$earnings = $this->createMock(EarningMapper::class);
		$earnings->method('insert')->willReturnCallback(function (Earning $claim): Earning {
			if ($this->claimInsertFails) {
				throw new \RuntimeException('disk full');
			}
			$this->newClaims[] = $claim;

			return $claim;
		});
		$earnings->method('sourceRefsStartingWith')->willReturnCallback(fn (): array => $this->refsAlreadyHere['earning']);

		$categories = $this->createMock(CategoryMapper::class);
		$childcare = new Category();
		$childcare->setId(2);
		$childcare->setName('Childcare');
		$tech = new Category();
		$tech->setId(5);
		$tech->setName('Tech Help');
		$categories->method('findAll')->willReturn([$childcare, $tech]);

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(fn (string $uid): bool => in_array($uid, $this->accounts, true));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $this->admins, true));

		$this->db = $this->createMock(IDBConnection::class);

		/** @var GovernanceLog&MockObject $log */
		$log = $this->createMock(GovernanceLog::class);
		$log->method('record')->willReturnCallback(function (string $actor, string $action, ?string $subject = null, array $details = []): void {
			$this->logged[] = [$actor, $action, $subject, $details];
		});

		$connector = $this->createMock(SourceConnector::class);
		$connector->method('open')->willReturnCallback(fn (): Source => $this->source());

		$this->service = new ImportService($imports, $staged, $requests, $volunteers, $earnings, $categories, $users, $groups, $this->db, $connector, $log, new ImportPlanner());
	}

	private function source(): Source {
		return new class($this->sourceRequests, $this->sourceOffers, $this->sourceClaims) implements Source {
			/**
			 * @param list<array<string, mixed>> $requests
			 * @param list<array<string, mixed>> $offers
			 * @param list<array<string, mixed>> $claims
			 */
			public function __construct(
				private array $requests,
				private array $offers,
				private array $claims,
			) {
			}

			public function requests(): array {
				return $this->requests;
			}

			public function volunteers(): array {
				return $this->offers;
			}

			public function earnings(): array {
				return $this->claims;
			}

			public function categoryNames(): array {
				return ['1' => 'Childcare', '2' => 'Tech Help'];
			}
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function connection(): array {
		return ['host' => 'nexttime-db', 'database' => 'nextcloud', 'user' => 'nextcloud', 'password' => self::PASSWORD];
	}

	private function stage(string $admin = 'root', string $label = 'Old Dev'): Import {
		return $this->service->stage($admin, $label, 'pgsql', $this->connection());
	}

	public function testOnlyAdminsMayDoAnythingWithImports(): void {
		$stagedByAdmin = $this->stage();
		$calls = [
			fn () => $this->service->preview('ana', 'x', 'pgsql', []),
			fn () => $this->service->stage('ana', 'x', 'pgsql', []),
			fn () => $this->service->finalize($stagedByAdmin->getId(), 'ana'),
			fn () => $this->service->cancel($stagedByAdmin->getId(), 'ana'),
			fn () => $this->service->recent('ana'),
			fn () => $this->service->detail($stagedByAdmin->getId(), 'ana'),
		];
		foreach ($calls as $call) {
			try {
				$call();
				$this->fail('a member got through');
			} catch (NotAuthorizedException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testPreviewWritesNothingAndLogsNothing(): void {
		$preview = $this->service->preview('root', 'Old Dev', 'pgsql', $this->connection());

		$this->assertSame(2, $preview['counts']['requests']);
		$this->assertSame(2, $preview['counts']['claims']);
		$this->assertSame([], $this->store);
		$this->assertSame([], $this->held);
		$this->assertSame([], $this->logged);
	}

	public function testStagingHoldsTheRowsWritesNoneAndLogsOnlyTotals(): void {
		$import = $this->stage();

		$this->assertSame(Import::STAGED, $import->getStatus());
		$this->assertSame('root', $import->getStagedBy());
		$this->assertEqualsWithDelta(time() + 14 * 86400, $import->getExpiresAt()->getTimestamp(), 5);
		$this->assertCount(5, $this->held, 'two requests, one offer, two claims');
		$this->assertSame([], $this->newRequests);
		$this->assertSame([], $this->newClaims);
		$this->assertCount(1, $this->logged);
		[$actor, $action, $subject, $details] = $this->logged[0];
		$this->assertSame(['root', 'import.staged', 'Old Dev'], [$actor, $action, $subject]);
		$this->assertSame(2, $details['requests']);
		$this->assertSame(1, $details['claimsApprovedBefore']);
		foreach ($details as $value) {
			$this->assertIsScalar($value, 'the log shows flat values');
		}
	}

	public function testNoConnectionDetailIsStoredOrLoggedAnywhere(): void {
		$import = $this->stage();

		$everything = json_encode([$import->jsonSerialize(), $import->getReport(), array_map(static fn (ImportStagedRow $r): string => $r->getData(), $this->held), $this->logged]);
		$this->assertStringNotContainsString(self::PASSWORD, (string)$everything);
		$this->assertStringNotContainsString('nexttime-db', (string)$everything);
	}

	public function testAnotherStagingFromTheSameSourceIsRefusedWhileOneWaits(): void {
		$this->stage();

		$this->expectException(InvalidStateException::class);
		$this->expectExceptionMessage('still waiting for a second admin');
		$this->stage('boss');
	}

	public function testNothingToImportIsRefused(): void {
		$this->accounts = ['root', 'boss'];

		$this->expectException(InvalidStateException::class);
		$this->expectExceptionMessage('nothing to import');
		$this->stage();
	}

	public function testTheAdminWhoStagedCannotFinalize(): void {
		$import = $this->stage();

		try {
			$this->service->finalize($import->getId(), 'root');
			$this->fail('the stager finalized their own import');
		} catch (NotAuthorizedException $e) {
			$this->assertStringContainsString('different admin', $e->getMessage());
		}
		$this->assertSame([], $this->newRequests);
		$this->assertSame(Import::STAGED, $this->store[$import->getId()]->getStatus());
		$this->assertCount(5, $this->held);
	}

	public function testASecondAdminFinalizesAndEverythingIsWritten(): void {
		$import = $this->stage();

		$done = $this->service->finalize($import->getId(), 'boss');

		$this->assertSame(Import::FINALIZED, $done->getStatus());
		$this->assertSame('boss', $done->getDecidedBy());
		$this->assertCount(2, $this->newRequests);
		$this->assertSame('old-dev:r:1', $this->newRequests[0]->getSourceRef());
		$this->assertSame('ana', $this->newRequests[0]->getRequesterId());
		$this->assertSame(2, $this->newRequests[0]->getCategoryId(), 'the category here, found by name');
		$this->assertSame('published', $this->newRequests[0]->getReviewStatus());
		$this->assertCount(1, $this->newOffers);
		$this->assertSame(100, $this->newOffers[0]->getRequestId(), 'the offer follows its request to the new id');
		$this->assertSame('3.00', $this->newOffers[0]->getProposedHours(), 'hours as they were');
		$this->assertCount(2, $this->newClaims);
		$this->assertSame([], $this->held, 'the held rows are deleted');
		$this->assertSame('import.finalized', $this->logged[1][1]);
		$this->assertSame('boss', $this->logged[1][0]);
		$this->assertSame(['requests' => 2, 'offers' => 1, 'claims' => 2], array_intersect_key($this->logged[1][3], ['requests' => 1, 'offers' => 1, 'claims' => 1]));
	}

	public function testClaimsAreWrittenPendingMarkedApprovedBeforeAndAlreadyNotified(): void {
		$import = $this->stage();

		$this->service->finalize($import->getId(), 'boss');

		[$approved, $pending] = $this->newClaims;
		$this->assertSame('pending', $approved->getStatus());
		$this->assertSame('pending', $pending->getStatus());
		$this->assertSame('2026-10-04 09:00:00', $approved->getApprovedBeforeAt()?->format('Y-m-d H:i:s'));
		$this->assertNull($pending->getApprovedBeforeAt());
		$this->assertNull($approved->getApprovedAt());
		$this->assertNull($approved->getApproverId());
		$this->assertNull($approved->getEvidenceFileId());
		$this->assertSame('1.50', $pending->getActualHoursEarned(), 'hours are copied, not recalculated');
		$this->assertGreaterThanOrEqual(2, $approved->getNotifiedStage(), 'so the reminder job does not nudge about every old claim');
	}

	public function testAPersonWhoWentAwayBetweenStagingAndFinalizingIsSkipped(): void {
		$import = $this->stage();
		$this->accounts = ['ana', 'root', 'boss'];

		$done = $this->service->finalize($import->getId(), 'boss');

		$this->assertCount(1, $this->newRequests, 'only ana\'s');
		$this->assertCount(0, $this->newOffers, 'ben\'s offer');
		$this->assertCount(1, $this->newClaims, 'only ana\'s');
		$this->assertSame(3, $done->countsData()['skipped']);
	}

	public function testRowsAlreadyThereAreNotWrittenTwice(): void {
		$import = $this->stage();
		$this->refsAlreadyHere['earning'] = ['old-dev:e:20' => true];

		$this->service->finalize($import->getId(), 'boss');

		$this->assertCount(1, $this->newClaims);
		$this->assertSame('old-dev:e:21', $this->newClaims[0]->getSourceRef());
	}

	public function testAnImportCannotBeFinalizedTwice(): void {
		$import = $this->stage();
		$this->service->finalize($import->getId(), 'boss');

		$this->expectException(InvalidStateException::class);
		$this->expectExceptionMessage('already finalized');
		$this->service->finalize($import->getId(), 'boss');
	}

	public function testAnExpiredImportIsClosedNotWritten(): void {
		$import = $this->stage();
		$import->setExpiresAt(new \DateTime('-1 hour'));

		try {
			$this->service->finalize($import->getId(), 'boss');
			$this->fail('an expired import was finalized');
		} catch (InvalidStateException $e) {
			$this->assertStringContainsString('expired', $e->getMessage());
		}
		$this->assertSame(Import::EXPIRED, $import->getStatus());
		$this->assertSame([], $this->newRequests);
		$this->assertSame([], $this->held);
		$this->assertSame(['system', 'import.expired'], [$this->logged[1][0], $this->logged[1][1]]);
	}

	public function testEitherAdminMayCancelAndTheHeldRowsGoAway(): void {
		$first = $this->stage();
		$cancelled = $this->service->cancel($first->getId(), 'root');

		$this->assertSame(Import::CANCELLED, $cancelled->getStatus());
		$this->assertSame([], $this->held);
		$this->assertSame(['root', 'import.cancelled'], [$this->logged[1][0], $this->logged[1][1]]);

		$second = $this->stage('root', 'Another source');
		$this->service->cancel($second->getId(), 'boss');
		$this->assertSame(Import::CANCELLED, $this->store[$second->getId()]->getStatus());
	}

	public function testADecidedImportCannotBeCancelled(): void {
		$import = $this->stage();
		$this->service->finalize($import->getId(), 'boss');

		$this->expectException(InvalidStateException::class);
		$this->service->cancel($import->getId(), 'root');
	}

	public function testStaleImportsAreExpiredByTheJobAndLoggedAsSystem(): void {
		$stale = $this->stage();
		$stale->setExpiresAt(new \DateTime('-1 day'));
		$fresh = $this->stage('root', 'Another source');

		$this->assertSame(1, $this->service->expireStale());

		$this->assertSame(Import::EXPIRED, $stale->getStatus());
		$this->assertSame(Import::STAGED, $fresh->getStatus());
		$this->assertSame(['system', 'import.expired', 'Old Dev'], array_slice(end($this->logged), 0, 3));
		$this->assertSame(array_fill(0, 5, $fresh->getId()), array_map(static fn (ImportStagedRow $row): int => $row->getImportId(), $this->held), 'only the fresh import keeps its rows');
	}

	public function testAFailureWhileWritingRollsBackAndTheImportStaysStaged(): void {
		$import = $this->stage();
		$this->claimInsertFails = true;
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		try {
			$this->service->finalize($import->getId(), 'boss');
			$this->fail('the failure was swallowed');
		} catch (\RuntimeException $e) {
			$this->assertSame('disk full', $e->getMessage());
		}
		$this->assertSame(Import::STAGED, $import->getStatus());
		$this->assertCount(1, $this->logged, 'only the staging line; nothing says it was finalized');
	}

	public function testAFailureWhileStagingRollsBackAndLogsNothing(): void {
		$this->stagingInsertFails = true;
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		try {
			$this->stage();
			$this->fail('the failure was swallowed');
		} catch (\RuntimeException $e) {
			$this->assertSame('disk full', $e->getMessage());
		}
		$this->assertSame([], $this->logged);
	}

	public function testAFailureWhileCancellingRollsBackAndLogsNothingMore(): void {
		$import = $this->stage();
		$this->deleteFails = true;
		$this->db->expects($this->once())->method('rollBack');

		try {
			$this->service->cancel($import->getId(), 'boss');
			$this->fail('the failure was swallowed');
		} catch (\RuntimeException $e) {
			$this->assertSame('locked', $e->getMessage());
		}
		$this->assertCount(1, $this->logged, 'only the staging line');
	}

	public function testTheDetailIsForAdminsAndSaysWhoMayFinalize(): void {
		$import = $this->stage();

		$this->assertFalse($this->service->detail($import->getId(), 'root')['canFinalize']);
		$this->assertTrue($this->service->detail($import->getId(), 'boss')['canFinalize']);
		$this->assertArrayHasKey('people', $this->service->detail($import->getId(), 'boss')['report']);
	}
}
