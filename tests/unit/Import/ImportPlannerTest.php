<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Import;

use OCA\TimeBank\Import\ImportPlan;
use OCA\TimeBank\Import\ImportPlanner;
use OCA\TimeBank\Import\Source;
use PHPUnit\Framework\TestCase;

/**
 * What an import from an older NextTime brings in and what it leaves out, row by row. Pure logic:
 * no database, so every rule is checked directly.
 */
final class ImportPlannerTest extends TestCase {

	private const NO_REFS = ['request' => [], 'volunteer' => [], 'earning' => []];

	/** @var array<string, string> the old system's categories */
	private array $oldCategories = ['1' => 'Childcare', '2' => 'Tech Help'];
	/** @var array<string, int> the categories here */
	private array $categoriesHere = ['childcare' => 2, 'tech help' => 5];
	/** @var list<string> accounts that exist here */
	private array $accounts = ['ana', 'ben'];
	/** @var list<array<string, mixed>> */
	private array $requests = [];
	/** @var list<array<string, mixed>> */
	private array $volunteers = [];
	/** @var list<array<string, mixed>> */
	private array $earnings = [];

	protected function setUp(): void {
		parent::setUp();
		$this->requests = [
			['id' => 1, 'requester_id' => 'ana', 'title' => 'Homework help', 'description' => 'Fractions.', 'category_id' => 1, 'hours_budget' => '2.00', 'status' => 'open', 'priority' => 'normal', 'zip' => '97055', 'location' => '45.5, -122.4', 'latitude' => 45.5, 'longitude' => -122.4, 'created_at' => '2026-10-01 09:00:00'],
			['id' => 2, 'requester_id' => 'ben', 'title' => 'Fix a laptop', 'description' => 'Slow.', 'category_id' => 2, 'hours_budget' => '1.50', 'status' => 'completed', 'zip' => '97030', 'created_at' => '2026-10-02 09:00:00'],
		];
		$this->volunteers = [
			['id' => 10, 'request_id' => 1, 'volunteer_id' => 'ben', 'proposed_hours' => '3.00', 'message' => 'I can', 'status' => 'accepted', 'created_at' => '2026-10-01 10:00:00'],
		];
		$this->earnings = [
			['id' => 20, 'user_id' => 'ben', 'category_id' => 1, 'hours_claimed' => '3.00', 'actual_hours_earned' => '3.00', 'description' => 'Tutoring', 'status' => 'approved', 'created_at' => '2026-10-03 09:00:00', 'approved_at' => '2026-10-04 09:00:00'],
			['id' => 21, 'user_id' => 'ana', 'category_id' => 2, 'hours_claimed' => '1.00', 'actual_hours_earned' => '1.50', 'description' => 'Setup', 'status' => 'pending', 'created_at' => '2026-10-05 09:00:00'],
		];
	}

	/**
	 * @param array{request: array<string, true>, volunteer: array<string, true>, earning: array<string, true>} $refs
	 */
	private function plan(string $label = 'Old Dev', array $refs = self::NO_REFS): ImportPlan {
		$source = new class($this->requests, $this->volunteers, $this->earnings, $this->oldCategories) implements Source {
			/**
			 * @param list<array<string, mixed>> $requests
			 * @param list<array<string, mixed>> $volunteers
			 * @param list<array<string, mixed>> $earnings
			 * @param array<string, string> $categories
			 */
			public function __construct(
				private array $requests,
				private array $volunteers,
				private array $earnings,
				private array $categories,
			) {
			}

			public function requests(): array {
				return $this->requests;
			}

			public function volunteers(): array {
				return $this->volunteers;
			}

			public function earnings(): array {
				return $this->earnings;
			}

			public function categoryNames(): array {
				return $this->categories;
			}
		};

		return (new ImportPlanner())->plan($source, $label, fn (string $uid): bool => in_array($uid, $this->accounts, true), $this->categoriesHere, $refs);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row(ImportPlan $plan, string $ref): array {
		foreach ($plan->rows as $row) {
			if ($row['ref'] === $ref) {
				return $row['data'];
			}
		}
		$this->fail("no staged row $ref");
	}

	public function testEverythingThatMatchesComesIn(): void {
		$plan = $this->plan();

		$this->assertSame(2, $plan->counts['requests']);
		$this->assertSame(1, $plan->counts['offers']);
		$this->assertSame(2, $plan->counts['claims']);
		$this->assertSame(['requests' => 0, 'offers' => 0, 'claims' => 0], $plan->counts['leftOut']);
		$this->assertCount(5, $plan->rows);
	}

	public function testEveryRowCarriesAReferenceBuiltFromTheLabelAndTheOldId(): void {
		$plan = $this->plan('Old Dev!');

		$this->assertSame(['old-dev:r:1', 'old-dev:r:2', 'old-dev:v:10', 'old-dev:e:20', 'old-dev:e:21'], array_column($plan->rows, 'ref'));
	}

	public function testARowAlreadyImportedIsSkippedNotAddedTwice(): void {
		$plan = $this->plan('Old Dev', ['request' => ['old-dev:r:1' => true], 'volunteer' => [], 'earning' => ['old-dev:e:20' => true]]);

		$this->assertSame(1, $plan->counts['requests']);
		$this->assertSame(1, $plan->counts['claims']);
		$this->assertSame(['requests' => 1, 'offers' => 0, 'claims' => 1], $plan->counts['alreadyImported']);
		$this->assertNotContains('old-dev:r:1', array_column($plan->rows, 'ref'));
	}

	public function testAnOfferOnARequestImportedEarlierStillComesIn(): void {
		$plan = $this->plan('Old Dev', ['request' => ['old-dev:r:1' => true], 'volunteer' => [], 'earning' => []]);

		$this->assertSame(1, $plan->counts['offers']);
	}

	public function testPeopleAreMatchedByAccountAndNoneIsCreated(): void {
		$this->accounts = ['ana'];

		$plan = $this->plan();

		$this->assertSame(1, $plan->counts['requests'], 'ben has no account here');
		$this->assertSame(['requests' => 1, 'offers' => 1, 'claims' => 1], $plan->counts['leftOut']);
		$this->assertSame(['matched' => 1, 'unmatched' => 1], $plan->counts['people']);
		$this->assertFalse($plan->report['people']['ben']['matched']);
		$this->assertSame(3, $plan->report['leftOutReasons']['person not found here'], 'one request, one offer and one claim');
	}

	public function testTheReportCountsEachPersonsRowsInTheSourceMatchedOrNot(): void {
		$this->accounts = ['ana'];

		$plan = $this->plan();

		$this->assertSame(['matched' => true, 'requests' => 1, 'offers' => 0, 'claims' => 1], $plan->report['people']['ana']);
		$this->assertSame(['matched' => false, 'requests' => 1, 'offers' => 1, 'claims' => 1], $plan->report['people']['ben']);
	}

	public function testCategoriesAreMatchedByNameNotById(): void {
		$plan = $this->plan();

		$this->assertSame(2, $this->row($plan, 'old-dev:r:1')['categoryId']);
		$this->assertSame(5, $this->row($plan, 'old-dev:r:2')['categoryId']);
	}

	public function testACategoryThisInstallLacksLeavesItsRowsOutAndIsNamed(): void {
		unset($this->categoriesHere['tech help']);

		$plan = $this->plan();

		$this->assertSame(1, $plan->counts['requests']);
		$this->assertSame(['Tech Help' => 2], $plan->report['unmatchedCategories']);
	}

	public function testAnOfferWhoseRequestWasLeftOutIsLeftOutToo(): void {
		$this->accounts = ['ben'];

		$plan = $this->plan();

		$this->assertSame(1, $plan->counts['requests'], 'only ben\'s request');
		$this->assertSame(0, $plan->counts['offers'], 'the offer is on ana\'s request, which was left out');
		$this->assertArrayHasKey('its request was not imported', $plan->report['leftOutReasons']);
	}

	public function testAnOfferWithAnUnknownStatusIsLeftOut(): void {
		$this->volunteers[0]['status'] = 'mystery';

		$plan = $this->plan();

		$this->assertSame(0, $plan->counts['offers']);
		$this->assertArrayHasKey('offer has a status this version does not know', $plan->report['leftOutReasons']);
	}

	public function testHoursAreCopiedAsTheyWereNotRecalculated(): void {
		$plan = $this->plan();

		$claim = $this->row($plan, 'old-dev:e:21');
		$this->assertSame('1.00', $claim['hoursClaimed']);
		$this->assertSame('1.50', $claim['actualHoursEarned']);
		$this->assertSame('3.00', $this->row($plan, 'old-dev:v:10')['proposedHours']);
	}

	public function testAClaimApprovedBeforeComesInWithItsDateAndAPendingOneWithout(): void {
		$plan = $this->plan();

		$this->assertSame('2026-10-04 09:00:00', $this->row($plan, 'old-dev:e:20')['approvedBeforeAt']);
		$this->assertNull($this->row($plan, 'old-dev:e:21')['approvedBeforeAt']);
		$this->assertSame(1, $plan->counts['claimsApprovedBefore']);
	}

	public function testAClaimApprovedWithoutADateFallsBackToWhenItWasMade(): void {
		unset($this->earnings[0]['approved_at']);

		$plan = $this->plan();

		$this->assertSame('2026-10-03 09:00:00', $this->row($plan, 'old-dev:e:20')['approvedBeforeAt']);
	}

	public function testARejectedClaimIsLeftOut(): void {
		$this->earnings[1]['status'] = 'rejected';

		$plan = $this->plan();

		$this->assertSame(1, $plan->counts['claims']);
		$this->assertSame(1, $plan->counts['leftOut']['claims']);
		$this->assertArrayHasKey('claim was rejected in the old system', $plan->report['leftOutReasons']);
	}

	public function testAClaimInAVoteComesInAsPendingNotApprovedBefore(): void {
		$this->earnings[1]['status'] = 'voting';

		$plan = $this->plan();

		$this->assertNull($this->row($plan, 'old-dev:e:21')['approvedBeforeAt']);
		$this->assertSame(1, $plan->counts['claimsApprovedBefore']);
	}

	public function testNothingCarriesAnEvidenceFileOrAnApprover(): void {
		$this->earnings[0]['evidence_file_id'] = 99;
		$this->earnings[0]['approver_id'] = 'ben';

		$claim = $this->row($this->plan(), 'old-dev:e:20');

		$this->assertArrayNotHasKey('evidenceFileId', $claim);
		$this->assertArrayNotHasKey('approverId', $claim);
		$this->assertArrayNotHasKey('status', $claim, 'every claim is pending when it is written');
	}

	public function testARequestWithoutATitleIsLeftOutAndOneWithoutAZipIsCounted(): void {
		$this->requests[0]['title'] = '  ';
		unset($this->requests[1]['zip']);

		$plan = $this->plan();

		$this->assertSame(1, $plan->counts['requests']);
		$this->assertSame(1, $plan->counts['requestsWithoutZip']);
		$this->assertNull($this->row($plan, 'old-dev:r:2')['zip']);
		$this->assertArrayHasKey('request has no title', $plan->report['leftOutReasons']);
	}

	public function testAnOlderSourceWithFewerColumnsStillWorks(): void {
		$this->requests = [['id' => 1, 'requester_id' => 'ana', 'title' => 'Old one', 'category_id' => 1]];
		$this->volunteers = [];
		$this->earnings = [];

		$request = $this->row($this->plan(), 'old-dev:r:1');

		$this->assertSame('open', $request['status']);
		$this->assertSame('normal', $request['priority']);
		$this->assertSame('0', $request['hoursBudget']);
		$this->assertNull($request['latitude']);
		$this->assertNull($request['createdAt']);
	}

	public function testALabelThatReducesToNothingStillGetsAPrefix(): void {
		$this->assertSame('import', ImportPlanner::refPrefix('!!!'));
		$this->assertSame('old-dev-2', ImportPlanner::refPrefix('  Old Dev #2 '));
		$this->assertLessThanOrEqual(60, strlen(ImportPlanner::refPrefix(str_repeat('abc ', 50))));
	}

	public function testTheReportNotesWhatIsNotImported(): void {
		$notes = implode(' ', $this->plan()->report['notes']);

		$this->assertStringContainsString('pending', $notes);
		$this->assertStringContainsString('ledger', $notes);
	}

	public function testRunningThePlannerTwiceGivesTheSameAnswer(): void {
		$planner = new ImportPlanner();
		$source = new class() implements Source {
			public function requests(): array {
				return [['id' => 1, 'requester_id' => 'ana', 'title' => 'One', 'category_id' => 1]];
			}

			public function volunteers(): array {
				return [];
			}

			public function earnings(): array {
				return [];
			}

			public function categoryNames(): array {
				return ['1' => 'Childcare'];
			}
		};
		$args = [fn (string $uid): bool => $uid === 'ana', ['childcare' => 2], self::NO_REFS];

		$first = $planner->plan($source, 'x', ...$args);
		$second = $planner->plan($source, 'x', ...$args);

		$this->assertEquals($first->counts, $second->counts);
		$this->assertEquals($first->report, $second->report);
	}
}
