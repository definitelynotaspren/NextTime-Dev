<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\AppInfo\Application;
use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Chapter\Electorate;
use OCA\TimeBank\Db\Adjustment;
use OCA\TimeBank\Db\AdjustmentMapper;
use OCA\TimeBank\Db\AdjustmentVote;
use OCA\TimeBank\Db\AdjustmentVoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Manual balance adjustments (Decision 3 of the security plan).
 *
 * Who proposes: a facilitator for members of their own state, a national organizer for anyone, a
 * Nextcloud admin for anyone. Nobody ever adjusts their own balance, and nobody approves or votes on
 * their own proposal or on an adjustment to their own balance.
 *
 * - Facilitators and national organizers: at or under the threshold, a second facilitator of the
 *   member's state or a national organizer approves or rejects it. Over the threshold it goes to a
 *   vote of the state's chapter members (see Electorate: national organizers for members with no
 *   state, or whose state has fewer than three people who could vote). The vote
 *   closes after 14 days, or earlier once everyone eligible has voted: at least 3 votes and more
 *   approvals than rejections apply it; 3 or more votes otherwise reject it; fewer than 3 expire it.
 * - Admins (break-glass, unchanged): at or under the threshold it applies at once; over it, a second
 *   person approves.
 *
 * Every step is written to the public governance log, and the ledger entry names who proposed and
 * who decided.
 */
class AdjustmentService {

	public const float DEFAULT_THRESHOLD = 5.0;
	public const float MIN_THRESHOLD = 1.0;
	public const float MAX_THRESHOLD = 20.0;
	public const float MAX_ADJUSTMENT = 100.0;
	public const int EXPIRY_DAYS = 14;
	public const int MIN_REASON_LENGTH = 10;
	public const int MIN_VOTES = 3;

	/** Stored as the approver of an adjustment that a vote applied. */
	public const string BY_VOTE = 'community-vote';

	public function __construct(
		private AdjustmentMapper $mapper,
		private AdjustmentVoteMapper $votes,
		private BalanceService $balances,
		private TransactionService $transactions,
		private GovernanceLog $log,
		private ViewerResolver $viewers,
		private ApproverPolicy $policy,
		private ChapterDirectory $chapters,
		private Electorate $electorate,
		private IGroupManager $groups,
		private IUserManager $users,
		private IAppConfig $config,
		private IDBConnection $db,
	) {
	}

	public function getThreshold(): float {
		$value = (float)$this->config->getValueString(Application::APP_ID, 'adjustment_threshold', (string)self::DEFAULT_THRESHOLD);

		return $value >= self::MIN_THRESHOLD && $value <= self::MAX_THRESHOLD ? $value : self::DEFAULT_THRESHOLD;
	}

	/**
	 * Admins only. Logged publicly with the old and new value and the reason.
	 *
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function setThreshold(string $adminId, float $hours, string $reason): float {
		if (!$this->groups->isAdmin($adminId)) {
			throw new NotAuthorizedException('Only administrators can change the threshold');
		}
		if ($hours < self::MIN_THRESHOLD || $hours > self::MAX_THRESHOLD) {
			throw new InvalidStateException(sprintf('The threshold must be between %g and %g hours', self::MIN_THRESHOLD, self::MAX_THRESHOLD));
		}
		$reason = $this->validReason($reason);

		$old = $this->getThreshold();
		$this->config->setValueString(Application::APP_ID, 'adjustment_threshold', (string)$hours);
		$this->log->record($adminId, 'setting.adjustment_threshold', null, [
			'from' => $old,
			'to' => $hours,
			'reason' => $reason,
		]);

		return $hours;
	}

	/** Whether this person may propose adjustments at all (for whom is checked per proposal). */
	public function mayPropose(string $userId): bool {
		$viewer = $this->viewers->forUser($userId);

		return $this->groups->isAdmin($userId) || $viewer->nationalOrganizer || $viewer->facilitatorOf !== [];
	}

	/**
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function propose(string $proposerId, string $userId, float $hours, string $reason): Adjustment {
		if ($proposerId === $userId) {
			throw new NotAuthorizedException('You cannot adjust your own balance. Ask another facilitator or a national organizer.');
		}
		$capacity = $this->policy->capacity($proposerId, $userId);
		if ($capacity === null) {
			throw new NotAuthorizedException('Only a facilitator of this member\'s state, a national organizer or an admin can propose an adjustment for them');
		}
		if (!$this->users->userExists($userId)) {
			throw new InvalidStateException('There is no member with that name');
		}
		if ($hours === 0.0 || abs($hours) > self::MAX_ADJUSTMENT) {
			throw new InvalidStateException(sprintf('Hours must not be zero and at most %g either way', self::MAX_ADJUSTMENT));
		}
		$reason = $this->validReason($reason);
		$small = abs($hours) <= $this->getThreshold();
		$byAdmin = $capacity['as'] === ApproverPolicy::AS_ADMIN;

		$now = new \DateTime();
		$adjustment = new Adjustment();
		$adjustment->setUserId($userId);
		$adjustment->setHours(number_format($hours, 2, '.', ''));
		$adjustment->setReason($reason);
		$adjustment->setProposerId($proposerId);
		$adjustment->setStatus($small || $byAdmin ? 'pending' : 'voting');
		$adjustment->setCreatedAt($now);
		$adjustment->setExpiresAt((clone $now)->modify('+' . self::EXPIRY_DAYS . ' days'));

		if ($small && $byAdmin) {
			return $this->apply($adjustment, null);
		}

		$adjustment = $this->mapper->insert($adjustment);
		if ($adjustment->getStatus() === 'voting') {
			$this->log->record($proposerId, 'adjustment.vote_opened', $userId, [
				'hours' => $hours,
				'reason' => $reason,
				'eligibleVoters' => count($this->electorate($adjustment)),
				'closes' => $adjustment->getExpiresAt()->format('Y-m-d H:i'),
			]);
		} else {
			$this->log->record($proposerId, 'adjustment.proposed', $userId, [
				'hours' => $hours,
				'reason' => $reason,
				'needsSecondPerson' => true,
			]);
		}

		return $adjustment;
	}

	/**
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function approve(int $id, string $approverId): Adjustment {
		$adjustment = $this->requirePending($id);
		$this->requireMayDecide($adjustment, $approverId);

		return $this->apply($adjustment, $approverId);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function reject(int $id, string $deciderId, string $note): Adjustment {
		$adjustment = $this->requirePending($id);
		$this->requireMayDecide($adjustment, $deciderId);

		return $this->close($adjustment, 'rejected', $deciderId, $this->validReason($note));
	}

	/**
	 * A vote on an adjustment that is being decided by its state's members. Public, one per person.
	 *
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function vote(int $id, string $voterId, bool $approve): Adjustment {
		$adjustment = $this->mapper->find($id);
		$this->settleIfDue($adjustment);
		if ($adjustment->getStatus() !== 'voting') {
			throw new InvalidStateException('This adjustment is not open for votes; it is ' . $adjustment->getStatus());
		}
		if ($voterId === $adjustment->getProposerId()) {
			throw new NotAuthorizedException('You proposed this, so you cannot vote on it');
		}
		if ($voterId === $adjustment->getUserId()) {
			throw new NotAuthorizedException('You cannot vote on an adjustment to your own balance');
		}
		if (!in_array($voterId, $this->electorate($adjustment), true)) {
			throw new NotAuthorizedException('Only chapter members of this member\'s state can vote on this');
		}
		foreach ($this->votes->findByAdjustment($id) as $existing) {
			if ($existing->getVoterId() === $voterId) {
				throw new InvalidStateException('You have already voted on this adjustment');
			}
		}

		$vote = new AdjustmentVote();
		$vote->setAdjustmentId($id);
		$vote->setVoterId($voterId);
		$vote->setVote($approve ? 'approve' : 'reject');
		$vote->setCreatedAt(new \DateTime());
		$this->votes->insert($vote);
		$this->log->record($voterId, 'adjustment.vote', $adjustment->getUserId(), [
			'adjustment' => $id,
			'vote' => $vote->getVote(),
		]);

		// Close early once nobody else can vote.
		$tally = $this->tally($adjustment);
		if ($tally['votes'] >= $tally['eligible']) {
			return $this->settle($adjustment);
		}

		return $adjustment;
	}

	/**
	 * The proposer can take their own proposal back while it is open.
	 *
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function withdraw(int $id, string $userId): Adjustment {
		$adjustment = $this->mapper->find($id);
		$this->settleIfDue($adjustment);
		if (!in_array($adjustment->getStatus(), ['pending', 'voting'], true)) {
			throw new InvalidStateException('This adjustment is already ' . $adjustment->getStatus());
		}
		if ($adjustment->getProposerId() !== $userId) {
			throw new NotAuthorizedException('Only the person who proposed it can withdraw it');
		}

		return $this->close($adjustment, 'withdrawn', null, null);
	}

	/**
	 * Recent adjustments this person may see, with what they may do to each. Admins and national
	 * organizers see all; a facilitator sees their states' members and their own proposals; a
	 * chapter member sees the votes open to them.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listFor(string $viewerId): array {
		$viewer = $this->viewers->forUser($viewerId);
		$seesAll = $viewer->nationalOrganizer || $this->groups->isAdmin($viewerId);

		$rows = [];
		foreach ($this->mapper->findRecent() as $adjustment) {
			$this->settleIfDue($adjustment);
			$voting = $adjustment->getStatus() === 'voting';
			$electorate = $voting ? $this->electorate($adjustment) : [];
			$canVote = $voting && in_array($viewerId, $electorate, true) && !$this->hasVoted($adjustment, $viewerId);
			$inMyState = array_intersect($viewer->facilitatorOf, $this->chapters->memberChapters($adjustment->getUserId())) !== [];
			$visible = $seesAll || $inMyState || $adjustment->getProposerId() === $viewerId
				|| ($voting && in_array($viewerId, $electorate, true));
			if (!$visible) {
				continue;
			}

			$row = $adjustment->jsonSerialize();
			$row['canDecide'] = $adjustment->getStatus() === 'pending' && $this->mayDecide($adjustment, $viewerId);
			$row['canWithdraw'] = in_array($adjustment->getStatus(), ['pending', 'voting'], true) && $adjustment->getProposerId() === $viewerId;
			$row['canVote'] = $canVote;
			if ($voting || $adjustment->getApproverId() === self::BY_VOTE || $this->votes->findByAdjustment($adjustment->getId()) !== []) {
				$row['vote'] = $this->tally($adjustment) + ['myVote' => $this->myVote($adjustment, $viewerId)];
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Whether this person could be the second pair of eyes on a pending adjustment.
	 */
	public function mayDecide(Adjustment $adjustment, string $userId): bool {
		if ($userId === $adjustment->getProposerId() || $userId === $adjustment->getUserId()) {
			return false;
		}

		return $this->policy->mayDecide($userId, $adjustment->getUserId());
	}

	/**
	 * Who may vote on it: see Electorate. Never the proposer or the member.
	 *
	 * @return list<string>
	 */
	private function electorate(Adjustment $adjustment): array {
		return $this->electorate->forMember($adjustment->getUserId(), [$adjustment->getProposerId()]);
	}

	/**
	 * @return array{approve: list<string>, reject: list<string>, votes: int, eligible: int, needed: int, closes: string}
	 */
	private function tally(Adjustment $adjustment): array {
		$approve = [];
		$reject = [];
		foreach ($this->votes->findByAdjustment($adjustment->getId()) as $vote) {
			if ($vote->getVote() === 'approve') {
				$approve[] = $vote->getVoterId();
			} else {
				$reject[] = $vote->getVoterId();
			}
		}

		return [
			'approve' => $approve,
			'reject' => $reject,
			'votes' => count($approve) + count($reject),
			'eligible' => count($this->electorate($adjustment)),
			'needed' => self::MIN_VOTES,
			'closes' => $adjustment->getExpiresAt()->format('Y-m-d H:i:s'),
		];
	}

	private function hasVoted(Adjustment $adjustment, string $userId): bool {
		return $this->myVote($adjustment, $userId) !== null;
	}

	private function myVote(Adjustment $adjustment, string $userId): ?string {
		foreach ($this->votes->findByAdjustment($adjustment->getId()) as $vote) {
			if ($vote->getVoterId() === $userId) {
				return $vote->getVote();
			}
		}

		return null;
	}

	/**
	 * Closes a vote: applied with at least MIN_VOTES votes and more approvals than rejections,
	 * rejected with MIN_VOTES or more otherwise, expired with fewer.
	 *
	 * @throws InvalidStateException
	 */
	private function settle(Adjustment $adjustment): Adjustment {
		$tally = $this->tally($adjustment);
		if ($tally['votes'] < self::MIN_VOTES) {
			return $this->expire($adjustment);
		}
		if (count($tally['approve']) > count($tally['reject'])) {
			$label = sprintf('community vote (for: %s; against: %s)', implode(', ', $tally['approve']), $tally['reject'] === [] ? 'nobody' : implode(', ', $tally['reject']));

			return $this->apply($adjustment, self::BY_VOTE, $label);
		}

		return $this->close($adjustment, 'rejected', null, sprintf('Rejected by vote: %d for, %d against', count($tally['approve']), count($tally['reject'])));
	}

	/**
	 * On day 14 a vote is settled and a pending proposal expires.
	 */
	private function settleIfDue(Adjustment $adjustment): void {
		if ($adjustment->getExpiresAt() >= new \DateTime()) {
			return;
		}
		if ($adjustment->getStatus() === 'pending') {
			$this->expire($adjustment);
		} elseif ($adjustment->getStatus() === 'voting') {
			try {
				$this->settle($adjustment);
			} catch (InvalidStateException) {
				// Applying would take the balance below zero: it cannot pass.
				$this->close($adjustment, 'rejected', null, 'Passed by vote, but the balance is too low to deduct it');
			}
		}
	}

	private function expire(Adjustment $adjustment): Adjustment {
		$adjustment->setStatus('expired');
		$adjustment->setDecidedAt(new \DateTime());
		$adjustment = $this->mapper->update($adjustment);
		$this->log->record('system', 'adjustment.expired', $adjustment->getUserId(), [
			'hours' => (float)$adjustment->getHours(),
			'proposedBy' => $adjustment->getProposerId(),
		]);

		return $adjustment;
	}

	/**
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 */
	private function requirePending(int $id): Adjustment {
		$adjustment = $this->mapper->find($id);
		$this->settleIfDue($adjustment);
		if ($adjustment->getStatus() === 'voting') {
			throw new InvalidStateException('This adjustment is decided by a vote of the member\'s state, not by one person');
		}
		if ($adjustment->getStatus() !== 'pending') {
			throw new InvalidStateException('This adjustment is already ' . $adjustment->getStatus());
		}

		return $adjustment;
	}

	/**
	 * @throws NotAuthorizedException
	 */
	private function requireMayDecide(Adjustment $adjustment, string $userId): void {
		if ($userId === $adjustment->getProposerId()) {
			throw new NotAuthorizedException('You proposed this, so someone else has to approve it');
		}
		if ($userId === $adjustment->getUserId()) {
			throw new NotAuthorizedException('You cannot decide on an adjustment to your own balance');
		}
		if (!$this->mayDecide($adjustment, $userId)) {
			throw new NotAuthorizedException('Only an admin, a facilitator of this member\'s state, or a national organizer can decide this');
		}
	}

	/**
	 * Moves the hours and writes the ledger entry in one database transaction, so a failure
	 * leaves neither half behind.
	 *
	 * @param string|null $approverLabel who decided, as the ledger shows it; defaults to $approverId
	 * @throws InvalidStateException
	 */
	private function apply(Adjustment $adjustment, ?string $approverId, ?string $approverLabel = null): Adjustment {
		$hours = (float)$adjustment->getHours();

		$this->db->beginTransaction();
		try {
			if ($hours > 0) {
				$this->balances->addHours($adjustment->getUserId(), $hours);
			} elseif (!$this->balances->hasSufficientBalance($adjustment->getUserId(), abs($hours))) {
				throw new InvalidStateException('That would take the balance below zero');
			} else {
				$this->balances->deductHours($adjustment->getUserId(), abs($hours));
			}
			$this->transactions->recordAdjustment(
				$adjustment->getUserId(),
				$hours,
				$adjustment->getReason(),
				$adjustment->getProposerId(),
				$approverLabel ?? $approverId,
			);

			$adjustment->setStatus('approved');
			$adjustment->setApproverId($approverId);
			$adjustment->setDecidedAt(new \DateTime());
			/** @psalm-suppress DocblockTypeContradiction Entity::$id is documented as int but is null until inserted */
			$adjustment = $adjustment->getId() === null
				? $this->mapper->insert($adjustment)
				: $this->mapper->update($adjustment);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->log->record($approverId === self::BY_VOTE ? 'system' : ($approverId ?? $adjustment->getProposerId()), 'adjustment.applied', $adjustment->getUserId(), [
			'hours' => $hours,
			'reason' => $adjustment->getReason(),
			'proposedBy' => $adjustment->getProposerId(),
			'approvedBy' => $approverLabel ?? $approverId,
		]);

		return $adjustment;
	}

	private function close(Adjustment $adjustment, string $status, ?string $deciderId, ?string $note): Adjustment {
		$adjustment->setStatus($status);
		$adjustment->setApproverId($deciderId);
		$adjustment->setDecisionNote($note);
		$adjustment->setDecidedAt(new \DateTime());
		$adjustment = $this->mapper->update($adjustment);

		$this->log->record($deciderId ?? ($status === 'withdrawn' ? $adjustment->getProposerId() : 'system'), 'adjustment.' . $status, $adjustment->getUserId(), [
			'hours' => (float)$adjustment->getHours(),
			'reason' => $adjustment->getReason(),
			'proposedBy' => $adjustment->getProposerId(),
			'note' => $note,
		]);

		return $adjustment;
	}

	/**
	 * @throws InvalidStateException
	 */
	private function validReason(string $reason): string {
		$reason = trim($reason);
		if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
			throw new InvalidStateException('Please give a reason of at least ' . self::MIN_REASON_LENGTH . ' characters. It is public.');
		}
		if (mb_strlen($reason) > 400) {
			throw new InvalidStateException('The reason cannot exceed 400 characters');
		}

		return $reason;
	}
}
