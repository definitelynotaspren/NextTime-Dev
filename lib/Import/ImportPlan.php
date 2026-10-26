<?php

declare(strict_types=1);

namespace OCA\TimeBank\Import;

/**
 * What an import would do, decided before anything is written: the rows to import (with the
 * old row they came from), totals for the public log, and the admin-only detail of who and what
 * did not match.
 *
 * @psalm-type StagedRow = array{kind: 'request'|'volunteer'|'earning', ref: string, data: array<string, mixed>}
 * @psalm-type Counts = array{requests: int, offers: int, claims: int, claimsApprovedBefore: int, alreadyImported: array{requests: int, offers: int, claims: int}, leftOut: array{requests: int, offers: int, claims: int}, requestsWithoutZip: int, people: array{matched: int, unmatched: int}}
 */
final class ImportPlan {

	/**
	 * @param list<StagedRow> $rows
	 * @param Counts $counts
	 * @param array<string, mixed> $report
	 */
	public function __construct(
		public readonly array $rows,
		public readonly array $counts,
		public readonly array $report,
	) {
	}
}
