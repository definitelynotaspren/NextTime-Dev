<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Db\Volunteer;
use OCA\TimeBank\Db\VolunteerMapper;
use OCA\TimeBank\Db\Withdrawal;
use OCA\TimeBank\Db\WithdrawalMapper;
use OCA\TimeBank\Notification\OfferNotifier;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Offers of help on a request.
 *
 * A request can have many volunteers, and the requester may accept several. Each
 * accepted volunteer is credited for the hours they proposed (hour for hour, never
 * split). A request is `open` until the first offer is accepted, then `in_progress`,
 * and goes back to `open` if every accepted volunteer withdraws.
 */
class VolunteerService {

	/** Request statuses in which people can still offer, be accepted, or step back. */
	private const array ACTIVE_REQUEST_STATUSES = ['open', 'in_progress'];

	private const int MAX_HOURS = 1000;
	private const int MIN_REASON_LENGTH = 3;
	private const int MAX_REASON_LENGTH = 1000;

	private VolunteerMapper $volunteerMapper;
	private RequestMapper $requestMapper;
	private WithdrawalMapper $withdrawalMapper;
	private OfferNotifier $notifier;

	public function __construct(
		VolunteerMapper $volunteerMapper,
		RequestMapper $requestMapper,
		WithdrawalMapper $withdrawalMapper,
		OfferNotifier $notifier,
	) {
		$this->volunteerMapper = $volunteerMapper;
		$this->requestMapper = $requestMapper;
		$this->withdrawalMapper = $withdrawalMapper;
		$this->notifier = $notifier;
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 */
	public function offer(int $requestId, string $volunteerId, array $data): Volunteer {
		$request = $this->requestMapper->find($requestId);

		if (!$this->isActive($request)) {
			throw new InvalidStateException('This request is not accepting volunteers');
		}

		if ($request->getRequesterId() === $volunteerId) {
			throw new InvalidStateException('You cannot volunteer for your own request');
		}

		$hours = (float)($data['proposedHours'] ?? 0);
		if ($hours <= 0 || $hours > self::MAX_HOURS) {
			throw new InvalidStateException('Proposed hours must be more than 0 and at most ' . self::MAX_HOURS);
		}

		try {
			$this->volunteerMapper->findByRequestAndVolunteer($requestId, $volunteerId);
			throw new InvalidStateException('You have already volunteered for this request');
		} catch (DoesNotExistException) {
			// No earlier offer from this person, so they can proceed.
		}

		$volunteer = new Volunteer();
		$volunteer->setRequestId($requestId);
		$volunteer->setVolunteerId($volunteerId);
		$volunteer->setProposedHours((string)$hours);
		$volunteer->setMessage(Input::string($data['message'] ?? null));
		$volunteer->setStatus('offered');
		$volunteer->setCreatedAt(new \DateTime());

		$volunteer = $this->volunteerMapper->insert($volunteer);
		$this->notifier->offered($volunteer, $request);

		return $volunteer;
	}

	/**
	 * Steps back from an offer. Withdrawing an offer that was already accepted needs
	 * a reason, which is kept in the log of withdrawn help, and reopens the request
	 * if no accepted volunteers are left.
	 *
	 * @throws DoesNotExistException
	 * @throws NotAuthorizedException
	 * @throws InvalidStateException
	 */
	public function withdraw(int $volunteerId, string $userId, ?string $reason = null): void {
		$volunteer = $this->volunteerMapper->find($volunteerId);

		if ($volunteer->getVolunteerId() !== $userId) {
			throw new NotAuthorizedException('Not authorized to withdraw this volunteer offer');
		}

		$request = $this->requestMapper->find($volunteer->getRequestId());
		if (!$this->isActive($request)) {
			throw new InvalidStateException('This request is finished, so the offer can no longer be withdrawn');
		}

		$status = $volunteer->getStatus();
		if (!in_array($status, ['offered', 'accepted'], true)) {
			throw new InvalidStateException('Only a pending or accepted offer can be withdrawn');
		}

		$reason = $reason === null ? null : trim($reason);
		if ($reason === '') {
			$reason = null;
		}

		if ($status === 'accepted') {
			if ($reason === null || mb_strlen($reason) < self::MIN_REASON_LENGTH) {
				throw new InvalidStateException('Please say why you are withdrawing your help');
			}
			if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
				throw new InvalidStateException('Please keep the reason under ' . self::MAX_REASON_LENGTH . ' characters');
			}

			$entry = new Withdrawal();
			$entry->setRequestId($request->getId());
			$entry->setVolunteerId($userId);
			$entry->setOfferStatus($status);
			$entry->setProposedHours((string)$volunteer->effectiveHours());
			$entry->setReason($reason);
			$entry->setCreatedAt(new \DateTime());
			$this->withdrawalMapper->insert($entry);
		}

		$this->volunteerMapper->delete($volunteer);
		$this->notifier->answered($volunteer);
		$this->reopenIfNoAcceptedVolunteers($request);
	}

	/**
	 * Accepts an offer. Several offers on one request can be accepted; the first one
	 * moves the request to `in_progress`.
	 *
	 * @throws DoesNotExistException
	 * @throws NotAuthorizedException
	 * @throws InvalidStateException
	 */
	public function accept(int $volunteerId, string $requesterId, ?float $agreedHours = null): Volunteer {
		$volunteer = $this->volunteerMapper->find($volunteerId);
		$request = $this->requestMapper->find($volunteer->getRequestId());

		$this->requireRequester($request, $requesterId, 'accept volunteers for');
		$this->requireActive($request);

		if ($volunteer->getStatus() !== 'offered') {
			throw new InvalidStateException('Only a pending offer can be accepted');
		}

		// The requester may settle on hours between what they posted and what the volunteer offered.
		// Left empty, the offered hours stand.
		$offered = (float)$volunteer->getProposedHours();
		$agreed = $agreedHours ?? $offered;
		$low = min($offered, (float)$request->getHoursBudget());
		$high = max($offered, (float)$request->getHoursBudget());
		if ($agreed < $low || $agreed > $high) {
			throw new InvalidStateException(sprintf('The agreed hours must be between %s (posted or offered, whichever is less) and %s', self::plainNumber($low), self::plainNumber($high)));
		}

		$volunteer->setAgreedHours((string)$agreed);
		$volunteer->setStatus('accepted');
		$updated = $this->volunteerMapper->update($volunteer);

		if ($request->getStatus() === 'open') {
			$request->setStatus('in_progress');
			$request->setUpdatedAt(new \DateTime());
			$this->requestMapper->update($request);
		}

		$this->notifier->answered($updated);
		$this->notifier->accepted($updated, $request);

		return $updated;
	}

	/**
	 * @throws DoesNotExistException
	 * @throws NotAuthorizedException
	 * @throws InvalidStateException
	 */
	public function decline(int $volunteerId, string $requesterId): Volunteer {
		$volunteer = $this->volunteerMapper->find($volunteerId);
		$request = $this->requestMapper->find($volunteer->getRequestId());

		$this->requireRequester($request, $requesterId, 'decline volunteers for');
		$this->requireActive($request);

		if ($volunteer->getStatus() !== 'offered') {
			throw new InvalidStateException('Only a pending offer can be declined');
		}

		$volunteer->setStatus('declined');
		$declined = $this->volunteerMapper->update($volunteer);
		$this->notifier->answered($declined);

		return $declined;
	}

	/**
	 * The caller's offers with a short summary of each request, for the My offers tab.
	 * An offer whose request no longer exists is left out.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getMyOffersWithRequests(string $userId): array {
		$rows = [];
		foreach ($this->volunteerMapper->findByVolunteer($userId) as $offer) {
			try {
				$request = $this->requestMapper->find($offer->getRequestId());
			} catch (DoesNotExistException) {
				continue;
			}
			$row = $offer->jsonSerialize();
			$row['request'] = [
				'id' => $request->getId(),
				'title' => $request->getTitle(),
				'status' => $request->getStatus(),
			];
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * The caller's own log of withdrawn help.
	 *
	 * @return Withdrawal[]
	 */
	public function getMyWithdrawals(string $userId): array {
		return $this->withdrawalMapper->findByVolunteer($userId);
	}

	private function isActive(Request $request): bool {
		return in_array($request->getStatus(), self::ACTIVE_REQUEST_STATUSES, true);
	}

	/**
	 * @throws InvalidStateException
	 */
	private function requireActive(Request $request): void {
		if (!$this->isActive($request)) {
			throw new InvalidStateException('This request is already finished');
		}
	}

	/**
	 * @throws NotAuthorizedException
	 */
	private function requireRequester(Request $request, string $userId, string $action): void {
		if ($request->getRequesterId() !== $userId) {
			throw new NotAuthorizedException("Not authorized to $action this request");
		}
	}

	private function reopenIfNoAcceptedVolunteers(Request $request): void {
		if ($request->getStatus() !== 'in_progress') {
			return;
		}

		foreach ($this->volunteerMapper->findByRequest($request->getId()) as $remaining) {
			if ($remaining->getStatus() === 'accepted') {
				return;
			}
		}

		$request->setStatus('open');
		$request->setUpdatedAt(new \DateTime());
		$this->requestMapper->update($request);
	}

	private static function plainNumber(float $hours): string {
		return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
	}
}
