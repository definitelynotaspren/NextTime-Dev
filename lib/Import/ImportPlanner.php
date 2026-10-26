<?php

declare(strict_types=1);

namespace OCA\TimeBank\Import;

/**
 * Decides, row by row, what an import from an older NextTime would bring in and what it would leave
 * out and why. It reads and counts; it writes nothing and does not touch the database, so the same
 * plan serves the preview and the staging.
 *
 * Rules (docs/proposals/data-sources.md):
 *  - Every imported row carries a source reference built from the source's label and its old id, so a
 *    row that was already imported is skipped, not added twice.
 *  - People are matched by identical Nextcloud user id. No account is created: a row for someone with
 *    no account here is left out and counted, and can come in on a later import once the account exists.
 *  - Categories are matched by name. One this install lacks leaves its rows out.
 *  - Hours are copied as they were. Claims arrive pending; one the old system had approved carries an
 *    "approved before" date. Rejected claims are left out. Balances and the old ledger are not imported,
 *    because nothing is credited until a claim is approved here.
 *  - Evidence files and withdrawal histories are not carried.
 *
 * @psalm-import-type StagedRow from ImportPlan
 */
final class ImportPlanner {

	private const array REQUEST_STATUSES = ['open', 'in_progress', 'completed', 'cancelled'];
	private const array OFFER_STATUSES = ['offered', 'accepted', 'declined', 'completed'];

	/** @var array<string, array{matched: bool, requests: int, offers: int, claims: int}> rows each person has in the source */
	private array $people = [];
	/** @var array<string, int> */
	private array $leftOutReasons = [];
	/** @var array<string, int> */
	private array $unmatchedCategories = [];

	/**
	 * @param callable(string): bool $userExists
	 * @param array<string, int> $categoryIdsByName lower-cased category name to its id here
	 * @param array{request: array<string, true>, volunteer: array<string, true>, earning: array<string, true>} $existingRefs
	 */
	public function plan(Source $source, string $label, callable $userExists, array $categoryIdsByName, array $existingRefs): ImportPlan {
		return (new self())->run($source, $label, $userExists, $categoryIdsByName, $existingRefs);
	}

	/**
	 * @param callable(string): bool $userExists
	 * @param array<string, int> $categoryIdsByName
	 * @param array{request: array<string, true>, volunteer: array<string, true>, earning: array<string, true>} $existingRefs
	 */
	private function run(Source $source, string $label, callable $userExists, array $categoryIdsByName, array $existingRefs): ImportPlan {
		$prefix = self::refPrefix($label);
		$oldCategories = $source->categoryNames();

		/** @var list<StagedRow> $rows */
		$rows = [];
		/** @var array{requests: int, offers: int, claims: int} $imported */
		$imported = ['requests' => 0, 'offers' => 0, 'claims' => 0];
		/** @var array{requests: int, offers: int, claims: int} $already */
		$already = ['requests' => 0, 'offers' => 0, 'claims' => 0];
		/** @var array{requests: int, offers: int, claims: int} $leftOut */
		$leftOut = ['requests' => 0, 'offers' => 0, 'claims' => 0];
		$approvedBeforeCount = 0;
		$withoutZip = 0;
		$notes = [];
		/** @var array<string, true> $requestRefsHere refs of requests that are in this plan or came in before */
		$requestRefsHere = $existingRefs['request'];

		foreach ($source->requests() as $row) {
			$ref = $prefix . ':r:' . self::text($row['id'] ?? '');
			$uid = self::text($row['requester_id'] ?? '');
			$known = $this->see($uid, 'requests', $userExists);
			if (isset($existingRefs['request'][$ref])) {
				$already['requests']++;
				continue;
			}
			$title = self::text($row['title'] ?? '');
			$categoryId = null;
			if (!$known) {
				$problem = 'person not found here';
			} elseif ($title === '') {
				$problem = 'request has no title';
			} else {
				$categoryId = $this->categoryHere($oldCategories, $row['category_id'] ?? null, $categoryIdsByName);
				$problem = $categoryId === null ? 'category not found here' : null;
			}
			if ($problem !== null) {
				$this->leftOut('requests', $problem, $leftOut);
				continue;
			}
			$status = self::text($row['status'] ?? '');
			$zip = self::text($row['zip'] ?? '');
			if ($zip === '') {
				$withoutZip++;
			}
			$rows[] = ['kind' => 'request', 'ref' => $ref, 'data' => [
				'requesterId' => $uid,
				'title' => $title,
				'description' => self::text($row['description'] ?? ''),
				'categoryId' => $categoryId,
				'hoursBudget' => self::number($row['hours_budget'] ?? null),
				'status' => in_array($status, self::REQUEST_STATUSES, true) ? $status : 'open',
				'priority' => self::text($row['priority'] ?? '') ?: 'normal',
				'deadline' => self::date($row['deadline'] ?? null),
				'location' => self::textOrNull($row['location'] ?? null),
				'latitude' => is_numeric($row['latitude'] ?? null) ? (float)$row['latitude'] : null,
				'longitude' => is_numeric($row['longitude'] ?? null) ? (float)$row['longitude'] : null,
				'zip' => $zip === '' ? null : $zip,
				'createdAt' => self::date($row['created_at'] ?? null),
				'updatedAt' => self::date($row['updated_at'] ?? null),
			]];
			$requestRefsHere[$ref] = true;
			$imported['requests']++;
		}

		foreach ($source->volunteers() as $row) {
			$ref = $prefix . ':v:' . self::text($row['id'] ?? '');
			$uid = self::text($row['volunteer_id'] ?? '');
			$requestRef = $prefix . ':r:' . self::text($row['request_id'] ?? '');
			$known = $this->see($uid, 'offers', $userExists);
			if (isset($existingRefs['volunteer'][$ref])) {
				$already['offers']++;
				continue;
			}
			$status = self::text($row['status'] ?? '');
			$problem = match (true) {
				!$known => 'person not found here',
				!isset($requestRefsHere[$requestRef]) => 'its request was not imported',
				!in_array($status, self::OFFER_STATUSES, true) => 'offer has a status this version does not know',
				default => null,
			};
			if ($problem !== null) {
				$this->leftOut('offers', $problem, $leftOut);
				continue;
			}
			$rows[] = ['kind' => 'volunteer', 'ref' => $ref, 'data' => [
				'requestRef' => $requestRef,
				'volunteerId' => $uid,
				'proposedHours' => self::number($row['proposed_hours'] ?? null),
				'message' => self::textOrNull($row['message'] ?? null),
				'status' => $status,
				'createdAt' => self::date($row['created_at'] ?? null),
			]];
			$imported['offers']++;
		}

		foreach ($source->earnings() as $row) {
			$ref = $prefix . ':e:' . self::text($row['id'] ?? '');
			$uid = self::text($row['user_id'] ?? '');
			$known = $this->see($uid, 'claims', $userExists);
			if (isset($existingRefs['earning'][$ref])) {
				$already['claims']++;
				continue;
			}
			$status = self::text($row['status'] ?? '');
			$categoryId = null;
			if (!$known) {
				$problem = 'person not found here';
			} elseif ($status === 'rejected') {
				$problem = 'claim was rejected in the old system';
			} else {
				$categoryId = $this->categoryHere($oldCategories, $row['category_id'] ?? null, $categoryIdsByName);
				$problem = $categoryId === null ? 'category not found here' : null;
			}
			if ($problem !== null) {
				$this->leftOut('claims', $problem, $leftOut);
				continue;
			}
			$approvedBefore = $status === 'approved'
				? (self::date($row['approved_at'] ?? null) ?? self::date($row['created_at'] ?? null))
				: null;
			$rows[] = ['kind' => 'earning', 'ref' => $ref, 'data' => [
				'userId' => $uid,
				'categoryId' => $categoryId,
				'hoursClaimed' => self::number($row['hours_claimed'] ?? null),
				'actualHoursEarned' => self::number($row['actual_hours_earned'] ?? null),
				'description' => self::text($row['description'] ?? ''),
				'createdAt' => self::date($row['created_at'] ?? null),
				'approvedBeforeAt' => $approvedBefore,
			]];
			$imported['claims']++;
			if ($approvedBefore !== null) {
				$approvedBeforeCount++;
			}
		}

		if ($imported['claims'] > 0) {
			$notes[] = 'Claims come in as pending. Hours are copied as they were; nothing is credited until a claim is approved here.';
		}
		$notes[] = 'The old ledger, balances, comments, votes and evidence files are not imported.';

		$matched = count(array_filter($this->people, static fn (array $p): bool => $p['matched']));
		ksort($this->people);
		arsort($this->leftOutReasons);
		arsort($this->unmatchedCategories);

		$counts = [
			'requests' => $imported['requests'],
			'offers' => $imported['offers'],
			'claims' => $imported['claims'],
			'claimsApprovedBefore' => $approvedBeforeCount,
			'alreadyImported' => $already,
			'leftOut' => $leftOut,
			'requestsWithoutZip' => $withoutZip,
			'people' => ['matched' => $matched, 'unmatched' => count($this->people) - $matched],
		];

		return new ImportPlan($rows, $counts, [
			'people' => $this->people,
			'unmatchedCategories' => $this->unmatchedCategories,
			'leftOutReasons' => $this->leftOutReasons,
			'notes' => $notes,
		]);
	}

	/**
	 * The text that goes in front of every source reference: the label, reduced to letters, digits and
	 * dashes. The same label is the same source, so use one label for one source.
	 */
	public static function refPrefix(string $label): string {
		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');

		return substr($slug === '' ? 'import' : $slug, 0, 60);
	}

	/**
	 * Counts one of the person's rows in the source, and says whether they have an account here.
	 *
	 * @param 'requests'|'offers'|'claims' $key
	 * @param callable(string): bool $userExists
	 */
	private function see(string $uid, string $key, callable $userExists): bool {
		if ($uid === '') {
			return false;
		}
		if (!isset($this->people[$uid])) {
			$this->people[$uid] = ['matched' => $userExists($uid), 'requests' => 0, 'offers' => 0, 'claims' => 0];
		}
		$this->people[$uid][$key]++;

		return $this->people[$uid]['matched'];
	}

	/**
	 * The id here of the category the old row used, found by name; null (and the name noted) if
	 * this install has no category of that name.
	 *
	 * @param array<string, string> $oldCategories
	 * @param array<string, int> $idsByName
	 */
	private function categoryHere(array $oldCategories, mixed $oldId, array $idsByName): ?int {
		$name = trim($oldCategories[self::text($oldId)] ?? '');
		$id = $name === '' ? null : ($idsByName[strtolower($name)] ?? null);
		if ($id === null) {
			$shown = $name === '' ? '(a category the old system no longer lists)' : $name;
			$this->unmatchedCategories[$shown] = ($this->unmatchedCategories[$shown] ?? 0) + 1;
		}

		return $id;
	}

	/**
	 * @param 'requests'|'offers'|'claims' $kind
	 * @param array{requests: int, offers: int, claims: int} $leftOut
	 */
	private function leftOut(string $kind, string $reason, array &$leftOut): void {
		$leftOut[$kind]++;
		$this->leftOutReasons[$reason] = ($this->leftOutReasons[$reason] ?? 0) + 1;
	}

	private static function text(mixed $value): string {
		return is_scalar($value) ? trim((string)$value) : '';
	}

	private static function textOrNull(mixed $value): ?string {
		$text = self::text($value);

		return $text === '' ? null : $text;
	}

	private static function number(mixed $value): string {
		return is_numeric($value) ? (string)$value : '0';
	}

	private static function date(mixed $value): ?string {
		$text = self::text($value);
		if ($text === '') {
			return null;
		}
		$date = date_create($text);

		return $date === false ? null : $date->format('Y-m-d H:i:s');
	}
}
