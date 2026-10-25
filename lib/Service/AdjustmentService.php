<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\AppInfo\Application;
use OCA\TimeBank\Db\Adjustment;
use OCA\TimeBank\Db\AdjustmentMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Manual balance adjustments.
 *
 * One admin can make a small adjustment. A larger one is a proposal that needs a second
 * person: another admin, or a facilitator of a chapter the member belongs to. Nobody ever
 * adjusts their own balance, and nobody approves their own proposal. Each step is written
 * to the public governance log, and the ledger entry names both people.
 */
class AdjustmentService {

	public const float DEFAULT_THRESHOLD = 5.0;
	public const float MIN_THRESHOLD = 1.0;
	public const float MAX_THRESHOLD = 20.0;
	public const float MAX_ADJUSTMENT = 100.0;
	public const int EXPIRY_DAYS = 14;
	public const int MIN_REASON_LENGTH = 10;

	public function __construct(
		private AdjustmentMapper $mapper,
		private BalanceService $balances,
		private TransactionService $transactions,
		private GovernanceLog $log,
		private ViewerResolver $viewers,
		private ApproverPolicy $policy,
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
		$this->requireAdmin($adminId);
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

	/**
	 * Small adjustments are applied at once; larger ones wait for a second person.
	 *
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function propose(string $proposerId, string $userId, float $hours, string $reason): Adjustment {
		$this->requireAdmin($proposerId);

		if ($proposerId === $userId) {
			throw new NotAuthorizedException('You cannot adjust your own balance. Ask another admin or facilitator.');
		}
		if (!$this->users->userExists($userId)) {
			throw new InvalidStateException('There is no member with that name');
		}
		if ($hours === 0.0 || abs($hours) > self::MAX_ADJUSTMENT) {
			throw new InvalidStateException(sprintf('Hours must not be zero and at most %g either way', self::MAX_ADJUSTMENT));
		}
		$reason = $this->validReason($reason);

		$now = new \DateTime();
		$adjustment = new Adjustment();
		$adjustment->setUserId($userId);
		$adjustment->setHours(number_format($hours, 2, '.', ''));
		$adjustment->setReason($reason);
		$adjustment->setProposerId($proposerId);
		$adjustment->setStatus('pending');
		$adjustment->setCreatedAt($now);
		$adjustment->setExpiresAt((clone $now)->modify('+' . self::EXPIRY_DAYS . ' days'));

		if (abs($hours) <= $this->getThreshold()) {
			return $this->apply($adjustment, null);
		}

		$adjustment = $this->mapper->insert($adjustment);
		$this->log->record($proposerId, 'adjustment.proposed', $userId, [
			'hours' => $hours,
			'reason' => $reason,
			'needsSecondPerson' => true,
		]);

		return $adjustment;
	}

	/**
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function approve(int $id, string $approverId): Adjustment {
		$adjustment = $this->requireOpen($id);
		$this->requireMayDecide($adjustment, $approverId);

		return $this->apply($adjustment, $approverId);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function reject(int $id, string $deciderId, string $note): Adjustment {
		$adjustment = $this->requireOpen($id);
		$this->requireMayDecide($adjustment, $deciderId);

		return $this->close($adjustment, 'rejected', $deciderId, $this->validReason($note));
	}

	/**
	 * The proposer can take their own proposal back.
	 *
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function withdraw(int $id, string $userId): Adjustment {
		$adjustment = $this->requireOpen($id);
		if ($adjustment->getProposerId() !== $userId) {
			throw new NotAuthorizedException('Only the person who proposed it can withdraw it');
		}

		return $this->close($adjustment, 'withdrawn', null, null);
	}

	/**
	 * Recent adjustments for the screen, with what this person may do to each.
	 *
	 * @return list<array<string, mixed>>
	 * @throws NotAuthorizedException
	 */
	public function listFor(string $viewerId): array {
		$viewer = $this->viewers->forUser($viewerId);
		if (!$this->groups->isAdmin($viewerId) && !$viewer->isStaff()) {
			throw new NotAuthorizedException('Only admins, facilitators and national organizers can see adjustments');
		}

		$rows = [];
		foreach ($this->mapper->findRecent() as $adjustment) {
			$this->expireIfDue($adjustment);
			$row = $adjustment->jsonSerialize();
			$row['canDecide'] = $adjustment->getStatus() === 'pending' && $this->mayDecide($adjustment, $viewerId);
			$row['canWithdraw'] = $adjustment->getStatus() === 'pending' && $adjustment->getProposerId() === $viewerId;
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Whether this person could be the second pair of eyes on the adjustment.
	 */
	public function mayDecide(Adjustment $adjustment, string $userId): bool {
		if ($userId === $adjustment->getProposerId() || $userId === $adjustment->getUserId()) {
			return false;
		}

		return $this->policy->mayDecide($userId, $adjustment->getUserId());
	}

	/**
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 */
	private function requireOpen(int $id): Adjustment {
		$adjustment = $this->mapper->find($id);
		$this->expireIfDue($adjustment);
		if ($adjustment->getStatus() !== 'pending') {
			throw new InvalidStateException('This adjustment is already ' . $adjustment->getStatus());
		}

		return $adjustment;
	}

	private function expireIfDue(Adjustment $adjustment): void {
		if ($adjustment->getStatus() === 'pending' && $adjustment->getExpiresAt() < new \DateTime()) {
			$adjustment->setStatus('expired');
			$adjustment->setDecidedAt(new \DateTime());
			$this->mapper->update($adjustment);
			$this->log->record('system', 'adjustment.expired', $adjustment->getUserId(), [
				'hours' => (float)$adjustment->getHours(),
				'proposedBy' => $adjustment->getProposerId(),
			]);
		}
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
	 * @throws InvalidStateException
	 */
	private function apply(Adjustment $adjustment, ?string $approverId): Adjustment {
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
				$approverId,
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

		$this->log->record($approverId ?? $adjustment->getProposerId(), 'adjustment.applied', $adjustment->getUserId(), [
			'hours' => $hours,
			'reason' => $adjustment->getReason(),
			'proposedBy' => $adjustment->getProposerId(),
			'approvedBy' => $approverId,
		]);

		return $adjustment;
	}

	private function close(Adjustment $adjustment, string $status, ?string $deciderId, ?string $note): Adjustment {
		$adjustment->setStatus($status);
		$adjustment->setApproverId($deciderId);
		$adjustment->setDecisionNote($note);
		$adjustment->setDecidedAt(new \DateTime());
		$adjustment = $this->mapper->update($adjustment);

		$this->log->record($deciderId ?? $adjustment->getProposerId(), 'adjustment.' . $status, $adjustment->getUserId(), [
			'hours' => (float)$adjustment->getHours(),
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

	/**
	 * @throws NotAuthorizedException
	 */
	private function requireAdmin(string $userId): void {
		if (!$this->groups->isAdmin($userId)) {
			throw new NotAuthorizedException('Only administrators can propose adjustments');
		}
	}
}
