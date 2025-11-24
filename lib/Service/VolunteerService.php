<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Db\Volunteer;
use OCA\TimeBank\Db\VolunteerMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class VolunteerService {

	private VolunteerMapper $volunteerMapper;
	private RequestMapper $requestMapper;

	public function __construct(
		VolunteerMapper $volunteerMapper,
		RequestMapper $requestMapper,
	) {
		$this->volunteerMapper = $volunteerMapper;
		$this->requestMapper = $requestMapper;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function offer(int $requestId, string $volunteerId, array $data): Volunteer {
		$request = $this->requestMapper->find($requestId);

		if ($request->getStatus() !== 'open') {
			throw new \Exception('This request is not accepting volunteers');
		}

		if ($request->getRequesterId() === $volunteerId) {
			throw new \Exception('You cannot volunteer for your own request');
		}

		try {
			$existing = $this->volunteerMapper->findByRequestAndVolunteer($requestId, $volunteerId);
			throw new \Exception('You have already volunteered for this request');
		} catch (DoesNotExistException $e) {
			// Good, we can proceed
		}

		$volunteer = new Volunteer();
		$volunteer->setRequestId($requestId);
		$volunteer->setVolunteerId($volunteerId);
		$volunteer->setProposedHours((string)$data['proposedHours']);
		$volunteer->setMessage($data['message'] ?? null);
		$volunteer->setStatus('offered');
		$volunteer->setCreatedAt(new \DateTime());

		$inserted = $this->volunteerMapper->insert($volunteer);

		if ($this->volunteerMapper->countByRequest($requestId) === 1) {
			$request->setStatus('in_progress');
			$request->setUpdatedAt(new \DateTime());
			$this->requestMapper->update($request);
		}

		return $inserted;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function withdraw(int $volunteerId, string $userId): void {
		$volunteer = $this->volunteerMapper->find($volunteerId);

		if ($volunteer->getVolunteerId() !== $userId) {
			throw new \Exception('Not authorized to withdraw this volunteer offer');
		}

		if ($volunteer->getStatus() === 'completed') {
			throw new \Exception('Cannot withdraw a completed volunteer offer');
		}

		$this->volunteerMapper->delete($volunteer);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function accept(int $volunteerId, string $requesterId): Volunteer {
		$volunteer = $this->volunteerMapper->find($volunteerId);
		$request = $this->requestMapper->find($volunteer->getRequestId());

		if ($request->getRequesterId() !== $requesterId) {
			throw new \Exception('Not authorized to accept volunteers for this request');
		}

		$volunteer->setStatus('accepted');
		return $this->volunteerMapper->update($volunteer);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function decline(int $volunteerId, string $requesterId): Volunteer {
		$volunteer = $this->volunteerMapper->find($volunteerId);
		$request = $this->requestMapper->find($volunteer->getRequestId());

		if ($request->getRequesterId() !== $requesterId) {
			throw new \Exception('Not authorized to decline volunteers for this request');
		}

		$volunteer->setStatus('declined');
		return $this->volunteerMapper->update($volunteer);
	}

	/**
	 * @return Volunteer[]
	 */
	public function getMyVolunteerOffers(string $userId): array {
		return $this->volunteerMapper->findByVolunteer($userId);
	}
}
