<?php

declare(strict_types=1);

namespace OCA\TimeBank\Import;

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
use OCA\TimeBank\Service\GovernanceLog;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\NotAuthorizedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Importing from another NextTime database, in two steps by two different admins
 * (docs/proposals/data-sources.md).
 *
 * An admin previews (nothing is written), then stages: the rows that would come in are held, with the
 * counts, and a line goes in the public governance log. A second admin finalizes or cancels; only
 * finalizing writes rows into the real tables, all or none. The admin who staged it can never finalize
 * it. A staged import nobody decides expires after 14 days and its held rows are deleted.
 *
 * Imported claims are written as pending, with "approved before" on those the old system had approved,
 * so nothing is credited by an import. They are written as already notified so the reminder job does not
 * nudge approvers about every old claim at once. No connection detail is stored or logged.
 *
 * @psalm-import-type Counts from ImportPlan
 */
class ImportService {

	public const string EXPIRES_AFTER = '+14 days';
	private const int MAX_LABEL = 100;
	/** The claim reminder job skips a claim notified at this stage or later. */
	private const int ALREADY_NOTIFIED = 2;

	public function __construct(
		private ImportMapper $imports,
		private ImportStagedRowMapper $staged,
		private RequestMapper $requests,
		private VolunteerMapper $volunteers,
		private EarningMapper $earnings,
		private CategoryMapper $categories,
		private IUserManager $users,
		private IGroupManager $groups,
		private IDBConnection $db,
		private SourceConnector $connector,
		private GovernanceLog $log,
		private ImportPlanner $planner,
	) {
	}

	/**
	 * What an import from this source would do. Writes nothing.
	 *
	 * @param array<string, mixed> $params the connection details (see DatabaseSource::connect)
	 * @return array{counts: Counts, report: array<string, mixed>}
	 */
	public function preview(string $adminId, string $label, string $kind, array $params): array {
		$this->requireAdmin($adminId);
		$plan = $this->plan(self::checkLabel($label), $kind, $params);

		return ['counts' => $plan->counts, 'report' => $plan->report];
	}

	/**
	 * Holds what the plan would import until a second admin decides.
	 *
	 * @param array<string, mixed> $params
	 * @throws InvalidStateException when there is nothing to import or one from this source is still waiting
	 */
	public function stage(string $adminId, string $label, string $kind, array $params): Import {
		$this->requireAdmin($adminId);
		$label = self::checkLabel($label);
		foreach ($this->imports->findStaged() as $waiting) {
			if ($waiting->getSourceLabel() === $label) {
				throw new InvalidStateException('Import #' . $waiting->getId() . ' from "' . $label . '" is still waiting for a second admin. Finalize or cancel it first.');
			}
		}
		$plan = $this->plan($label, $kind, $params);
		if ($plan->rows === []) {
			throw new InvalidStateException('There is nothing to import: ' . self::summary($plan->counts));
		}

		$now = new \DateTime();
		$import = new Import();
		$import->setStatus(Import::STAGED);
		$import->setSourceKind($kind);
		$import->setSourceLabel($label);
		$import->setStagedBy($adminId);
		$import->setStagedAt($now);
		$import->setExpiresAt((clone $now)->modify(self::EXPIRES_AFTER));
		$import->setCounts(json_encode($plan->counts, JSON_THROW_ON_ERROR));
		$import->setReport(json_encode($plan->report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

		$this->db->beginTransaction();
		try {
			$import = $this->imports->insert($import);
			foreach ($plan->rows as $row) {
				$held = new ImportStagedRow();
				$held->setImportId($import->getId());
				$held->setKind($row['kind']);
				$held->setSourceRef($row['ref']);
				$held->setData(json_encode($row['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
				$this->staged->insert($held);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->log->record($adminId, 'import.staged', $label, self::publicCounts($plan->counts) + ['source' => $kind]);

		return $import;
	}

	/**
	 * Writes a staged import. Only a different admin than the one who staged it may.
	 *
	 * @throws NotAuthorizedException for the admin who staged it, or anyone who is not an admin
	 * @throws InvalidStateException when it is no longer waiting
	 * @throws DoesNotExistException
	 */
	public function finalize(int $importId, string $adminId): Import {
		$this->requireAdmin($adminId);
		$import = $this->waiting($importId);
		if ($import->getStagedBy() === $adminId) {
			throw new NotAuthorizedException('A different admin has to finalize an import. Ask another admin to open this page.');
		}
		if ($import->getExpiresAt() < new \DateTime()) {
			$this->close($import, Import::EXPIRED, 'system', 'import.expired');
			throw new InvalidStateException('This import expired before anyone finalized it. Stage it again.');
		}

		$written = ['requests' => 0, 'offers' => 0, 'claims' => 0];
		$approvedBefore = 0;
		$skipped = 0;

		$this->db->beginTransaction();
		try {
			$prefix = ImportPlanner::refPrefix($import->getSourceLabel());
			$have = [
				'volunteer' => $this->volunteers->sourceRefsStartingWith($prefix),
				'earning' => $this->earnings->sourceRefsStartingWith($prefix),
			];
			$validCategories = [];
			foreach ($this->categories->findAll() as $category) {
				if ($category->getArchivedAt() === null) {
					$validCategories[$category->getId()] = true;
				}
			}
			/** @var array<string, int> $requestIds source reference to the id here */
			$requestIds = [];

			foreach ($this->staged->findByImport($importId) as $held) {
				$data = $held->dataArray();
				$ref = $held->getSourceRef();
				switch ($held->getKind()) {
					case 'request':
						$existing = $this->requests->findBySourceRef($ref);
						if ($existing !== null) {
							$requestIds[$ref] = $existing->getId();
							$skipped++;
						} elseif (!$this->stillValid($data, 'requesterId', $validCategories)) {
							$skipped++;
						} else {
							$requestIds[$ref] = $this->requests->insert($this->newRequest($ref, $data))->getId();
							$written['requests']++;
						}
						break;
					case 'volunteer':
						$requestRef = self::str($data['requestRef'] ?? '');
						$requestId = $requestIds[$requestRef] ?? $this->requests->findBySourceRef($requestRef)?->getId();
						$volunteerId = self::str($data['volunteerId'] ?? '');
						if ($requestId === null || isset($have['volunteer'][$ref]) || !$this->users->userExists($volunteerId) || $this->hasOffered($requestId, $volunteerId)) {
							$skipped++;
						} else {
							$this->volunteers->insert($this->newVolunteer($ref, $requestId, $data));
							$written['offers']++;
						}
						break;
					case 'earning':
						if (isset($have['earning'][$ref]) || !$this->stillValid($data, 'userId', $validCategories)) {
							$skipped++;
						} else {
							$claim = $this->newEarning($ref, $data);
							$this->earnings->insert($claim);
							$written['claims']++;
							if ($claim->getApprovedBeforeAt() !== null) {
								$approvedBefore++;
							}
						}
						break;
				}
			}

			$counts = $import->countsData();
			$counts['written'] = $written + ['claimsApprovedBefore' => $approvedBefore];
			$counts['skipped'] = $skipped;
			$import->setCounts(json_encode($counts, JSON_THROW_ON_ERROR));
			$import->setStatus(Import::FINALIZED);
			$import->setDecidedBy($adminId);
			$import->setDecidedAt(new \DateTime());
			$import = $this->imports->update($import);
			$this->staged->deleteByImport($importId);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->log->record($adminId, 'import.finalized', $import->getSourceLabel(), $written + [
			'claimsApprovedBefore' => $approvedBefore,
			'skipped' => $skipped,
			'stagedBy' => $import->getStagedBy(),
		]);

		return $import;
	}

	/**
	 * Drops a staged import. Either admin may: the one who staged it can take it back.
	 *
	 * @throws InvalidStateException when it is no longer waiting
	 */
	public function cancel(int $importId, string $adminId): Import {
		$this->requireAdmin($adminId);
		$import = $this->waiting($importId);

		return $this->close($import, Import::CANCELLED, $adminId, 'import.cancelled');
	}

	/**
	 * Marks staged imports nobody decided in time as expired and deletes their held rows.
	 *
	 * @return int how many expired
	 */
	public function expireStale(): int {
		$stale = $this->imports->findStagedExpiredBefore(new \DateTime());
		foreach ($stale as $import) {
			$this->close($import, Import::EXPIRED, 'system', 'import.expired');
		}

		return count($stale);
	}

	/**
	 * @return list<array<string, mixed>> newest first, without the reports
	 */
	public function recent(string $adminId): array {
		$this->requireAdmin($adminId);

		return array_values(array_map(static fn (Import $import): array => $import->jsonSerialize(), $this->imports->findRecent()));
	}

	/**
	 * One import with its admin-only report.
	 *
	 * @return array<string, mixed>
	 * @throws DoesNotExistException
	 */
	public function detail(int $importId, string $adminId): array {
		$this->requireAdmin($adminId);
		$import = $this->imports->find($importId);

		return $import->jsonSerialize() + ['report' => $import->reportData(), 'canFinalize' => $import->getStatus() === Import::STAGED && $import->getStagedBy() !== $adminId];
	}

	private function requireAdmin(string $userId): void {
		if (!$this->groups->isAdmin($userId)) {
			throw new NotAuthorizedException('Only a Nextcloud admin can import data');
		}
	}

	/**
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 */
	private function waiting(int $importId): Import {
		$import = $this->imports->find($importId);
		if ($import->getStatus() !== Import::STAGED) {
			throw new InvalidStateException('This import is already ' . $import->getStatus());
		}

		return $import;
	}

	private function close(Import $import, string $status, string $actor, string $action): Import {
		$this->db->beginTransaction();
		try {
			$import->setStatus($status);
			$import->setDecidedBy($actor);
			$import->setDecidedAt(new \DateTime());
			$import = $this->imports->update($import);
			$this->staged->deleteByImport($import->getId());
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->log->record($actor, $action, $import->getSourceLabel(), ['stagedBy' => $import->getStagedBy()]);

		return $import;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function plan(string $label, string $kind, array $params): ImportPlan {
		$source = $this->connector->open($kind, $params);
		$prefix = ImportPlanner::refPrefix($label);
		$categoryIds = [];
		foreach ($this->categories->findAll() as $category) {
			if ($category->getArchivedAt() === null) {
				$categoryIds[strtolower(trim($category->getName()))] = $category->getId();
			}
		}

		return $this->planner->plan(
			$source,
			$label,
			fn (string $uid): bool => $this->users->userExists($uid),
			$categoryIds,
			[
				'request' => $this->requests->sourceRefsStartingWith($prefix),
				'volunteer' => $this->volunteers->sourceRefsStartingWith($prefix),
				'earning' => $this->earnings->sourceRefsStartingWith($prefix),
			],
		);
	}

	/**
	 * Between staging and finalizing the person may have gone or the category been archived.
	 *
	 * @param array<string, mixed> $data
	 * @param array<int, true> $validCategories
	 */
	private function stillValid(array $data, string $personKey, array $validCategories): bool {
		return $this->users->userExists(self::str($data[$personKey] ?? ''))
			&& isset($validCategories[(int)($data['categoryId'] ?? 0)]);
	}

	private function hasOffered(int $requestId, string $volunteerId): bool {
		try {
			$this->volunteers->findByRequestAndVolunteer($requestId, $volunteerId);

			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function newRequest(string $ref, array $data): Request {
		$created = self::date($data['createdAt'] ?? null);
		$request = new Request();
		$request->setRequesterId(self::str($data['requesterId'] ?? ''));
		$request->setTitle(self::str($data['title'] ?? ''));
		$request->setDescription(self::str($data['description'] ?? ''));
		$request->setCategoryId((int)($data['categoryId'] ?? 0));
		$request->setHoursBudget(self::str($data['hoursBudget'] ?? '0'));
		$request->setStatus(self::str($data['status'] ?? 'open'));
		$request->setPriority(self::str($data['priority'] ?? 'normal'));
		$request->setDeadline(isset($data['deadline']) ? self::date($data['deadline']) : null);
		$request->setLocation(isset($data['location']) ? self::str($data['location']) : null);
		$request->setLatitude(is_numeric($data['latitude'] ?? null) ? (float)$data['latitude'] : null);
		$request->setLongitude(is_numeric($data['longitude'] ?? null) ? (float)$data['longitude'] : null);
		$request->setZip(isset($data['zip']) ? self::str($data['zip']) : null);
		$request->setMode('in_person');
		$request->setReviewStatus('published');
		$request->setCreatedAt($created);
		$request->setUpdatedAt(isset($data['updatedAt']) ? self::date($data['updatedAt']) : $created);
		$request->setSourceRef($ref);

		return $request;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function newVolunteer(string $ref, int $requestId, array $data): Volunteer {
		$offer = new Volunteer();
		$offer->setRequestId($requestId);
		$offer->setVolunteerId(self::str($data['volunteerId'] ?? ''));
		$offer->setProposedHours(self::str($data['proposedHours'] ?? '0'));
		$offer->setMessage(isset($data['message']) ? self::str($data['message']) : null);
		$offer->setStatus(self::str($data['status'] ?? 'offered'));
		$offer->setCreatedAt(self::date($data['createdAt'] ?? null));
		$offer->setSourceRef($ref);

		return $offer;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function newEarning(string $ref, array $data): Earning {
		$claim = new Earning();
		$claim->setUserId(self::str($data['userId'] ?? ''));
		$claim->setCategoryId((int)($data['categoryId'] ?? 0));
		$claim->setHoursClaimed(self::str($data['hoursClaimed'] ?? '0'));
		$claim->setActualHoursEarned(self::str($data['actualHoursEarned'] ?? '0'));
		$claim->setDescription(self::str($data['description'] ?? ''));
		$claim->setEvidenceFileId(null);
		$claim->setStatus('pending');
		$claim->setApproverId(null);
		$claim->setRejectionReason(null);
		$claim->setCreatedAt(self::date($data['createdAt'] ?? null));
		$claim->setApprovedAt(null);
		$claim->setApprovedBeforeAt(isset($data['approvedBeforeAt']) ? self::date($data['approvedBeforeAt']) : null);
		$claim->setNotifiedStage(self::ALREADY_NOTIFIED);
		$claim->setSourceRef($ref);

		return $claim;
	}

	private static function checkLabel(string $label): string {
		$label = trim($label);
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
			throw new InvalidStateException('Give the source a name of up to ' . self::MAX_LABEL . ' characters. Use the same name each time you import from the same source: it is how rows already imported are recognised.');
		}

		return $label;
	}

	/**
	 * @param Counts $counts
	 */
	private static function summary(array $counts): string {
		$already = array_sum($counts['alreadyImported']);
		$left = array_sum($counts['leftOut']);

		return "$already row(s) were already imported and $left could not be matched here.";
	}

	/**
	 * The totals that go in the public log, as flat values the log can show.
	 *
	 * @param Counts $counts
	 * @return array<string, int>
	 */
	private static function publicCounts(array $counts): array {
		return [
			'requests' => $counts['requests'],
			'offers' => $counts['offers'],
			'claims' => $counts['claims'],
			'claimsApprovedBefore' => $counts['claimsApprovedBefore'],
			'alreadyImported' => array_sum($counts['alreadyImported']),
			'leftOut' => array_sum($counts['leftOut']),
			'peopleMatched' => $counts['people']['matched'],
			'peopleUnmatched' => $counts['people']['unmatched'],
		];
	}

	private static function str(mixed $value): string {
		return is_scalar($value) ? (string)$value : '';
	}

	private static function date(mixed $value): \DateTime {
		try {
			return new \DateTime(is_string($value) && $value !== '' ? $value : 'now');
		} catch (\Exception) {
			return new \DateTime();
		}
	}
}
