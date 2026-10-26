<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\CommentMapper;
use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Db\UserStatsMapper;
use OCA\TimeBank\Db\VolunteerMapper;
use OCA\TimeBank\Db\WithdrawalMapper;
use OCA\TimeBank\Geo\Coordinates;
use OCA\TimeBank\Geo\ZipCenters;
use OCP\AppFramework\Db\DoesNotExistException;

class RequestService {

	private RequestMapper $requestMapper;
	private VolunteerMapper $volunteerMapper;
	private CommentMapper $commentMapper;
	private UserStatsMapper $statsMapper;
	private WithdrawalMapper $withdrawalMapper;

	public function __construct(
		RequestMapper $requestMapper,
		VolunteerMapper $volunteerMapper,
		CommentMapper $commentMapper,
		UserStatsMapper $statsMapper,
		WithdrawalMapper $withdrawalMapper,
		private RequestPresenter $presenter,
	) {
		$this->requestMapper = $requestMapper;
		$this->volunteerMapper = $volunteerMapper;
		$this->commentMapper = $commentMapper;
		$this->statsMapper = $statsMapper;
		$this->withdrawalMapper = $withdrawalMapper;
	}

	/**
	 * The request as this person may see it (see RequestPresenter for who gets the exact location).
	 *
	 * @return array<string, mixed>
	 */
	public function present(Request $request, string $viewerId): array {
		return $this->presenter->forUser($request, $viewerId);
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{requests: array<array-key, mixed>, total: int, limit: int, offset: int}
	 */
	public function getRequests(string $viewerId, array $filters = [], int $limit = 50, int $offset = 0): array {
		$requests = $this->requestMapper->findAll($filters, $limit, $offset);
		$total = $this->requestMapper->count($filters);

		$enrichedRequests = array_map(function ($request) use ($viewerId) {
			$volunteerCount = $this->volunteerMapper->countByRequest($request->getId());
			$commentCount = $this->commentMapper->countByRequest($request->getId());

			$data = $this->present($request, $viewerId);
			$data['volunteerCount'] = $volunteerCount;
			$data['commentCount'] = $commentCount;

			return $data;
		}, $requests);

		return [
			'requests' => $enrichedRequests,
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * Request, offers and comments. The requester also gets the reasons people gave for
	 * withdrawing their help; nobody else does, since reasons can be personal.
	 *
	 * @return array{request: array<string, mixed>, volunteers: array<array-key, mixed>, comments: array<array-key, mixed>, withdrawals?: array<array-key, mixed>}
	 * @throws DoesNotExistException
	 */
	public function getRequestDetails(int $id, string $viewerId): array {
		$request = $this->requestMapper->find($id);
		$volunteers = $this->volunteerMapper->findByRequest($id);
		$comments = $this->commentMapper->findByRequest($id);

		$enrichedVolunteers = array_map(function ($volunteer) use ($request) {
			$stats = $this->statsMapper->findByUserAndCategory(
				$volunteer->getVolunteerId(),
				$request->getCategoryId()
			);

			$data = $volunteer->jsonSerialize();
			$data['completedInCategory'] = $stats?->getCompletedRequests() ?? 0;
			$data['totalHoursProvided'] = $stats ? (float)$stats->getTotalHoursProvided() : 0;

			return $data;
		}, $volunteers);

		usort($enrichedVolunteers, fn (array $a, array $b): int => (int)$b['completedInCategory'] - (int)$a['completedInCategory']);

		$details = [
			'request' => $this->present($request, $viewerId),
			'volunteers' => $enrichedVolunteers,
			'comments' => array_map(fn ($c) => $c->jsonSerialize(), $comments),
		];

		if ($viewerId === $request->getRequesterId()) {
			$details['withdrawals'] = array_map(
				fn ($entry) => $entry->jsonSerialize(),
				$this->withdrawalMapper->findByRequest($id),
			);
		}

		return $details;
	}

	/**
	 * The poster's id if this viewer may not be told it, else null (for masking comments).
	 *
	 * @throws DoesNotExistException
	 */
	public function hiddenPoster(int $id, string $viewerId): ?string {
		return $this->presenter->hiddenPoster($this->requestMapper->find($id), $viewerId);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function createRequest(string $userId, array $data): Request {
		$request = new Request();
		$request->setRequesterId($userId);
		$request->setTitle(Input::string($data['title'] ?? null) ?? '');
		$request->setDescription(Input::string($data['description'] ?? null) ?? '');
		$request->setCategoryId((int)$data['categoryId']);
		$request->setHoursBudget((string)$data['hoursBudget']);
		$request->setStatus('open');
		$request->setPriority(Input::string($data['priority'] ?? null) ?? 'normal');
		$request->setMode('in_person');
		$request->setReviewStatus('published');
		$request->setShowUsername(filter_var($data['showUsername'] ?? false, FILTER_VALIDATE_BOOLEAN));
		$this->applyZip($request, $data);

		$deadline = Input::string($data['deadline'] ?? null);
		if ($deadline !== null && $deadline !== '') {
			$request->setDeadline(new \DateTime($deadline));
		}
		if (isset($data['location'])) {
			$request->setLocation(Input::string($data['location']));
		}
		$this->applyCoordinates($request, $data);

		$now = new \DateTime();
		$request->setCreatedAt($now);
		$request->setUpdatedAt($now);

		return $this->requestMapper->insert($request);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws DoesNotExistException
	 */
	public function updateRequest(int $id, string $userId, array $data): Request {
		$request = $this->requestMapper->find($id);

		if ($request->getRequesterId() !== $userId) {
			throw new NotAuthorizedException('Not authorized to update this request');
		}
		if ($request->getStatus() !== 'open') {
			throw new InvalidStateException('A request can only be edited while it is open');
		}

		// Same limits as posting a request.
		if (isset($data['title'])) {
			$title = trim((string)$data['title']);
			if (strlen($title) < 5 || strlen($title) > 200) {
				throw new InvalidStateException('Title must be 5 to 200 characters');
			}
			$request->setTitle($title);
		}
		if (isset($data['description'])) {
			$description = trim((string)$data['description']);
			if (strlen($description) < 20) {
				throw new InvalidStateException('Description must be at least 20 characters');
			}
			$request->setDescription($description);
		}
		if (isset($data['categoryId'])) {
			$request->setCategoryId((int)$data['categoryId']);
		}
		if (isset($data['hoursBudget'])) {
			$hours = (float)$data['hoursBudget'];
			if ($hours <= 0 || $hours > 1000) {
				throw new InvalidStateException('Hours budget must be above 0 and at most 1000');
			}
			$request->setHoursBudget((string)$hours);
		}
		if (isset($data['priority'])) {
			if (!in_array($data['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
				throw new InvalidStateException('Priority must be low, normal, high, or urgent');
			}
			$request->setPriority($data['priority']);
		}
		if (isset($data['showUsername'])) {
			$request->setShowUsername(filter_var($data['showUsername'], FILTER_VALIDATE_BOOLEAN));
		}
		$deadline = Input::string($data['deadline'] ?? null);
		if ($deadline !== null && $deadline !== '') {
			$request->setDeadline(new \DateTime($deadline));
		}
		if (isset($data['location'])) {
			$location = trim(Input::string($data['location']) ?? '');
			$request->setLocation($location === '' ? null : $location);
			if ($location === '') {
				// No exact location any more: the point goes with the text.
				$request->setLatitude(null);
				$request->setLongitude(null);
			}
		}
		$this->applyCoordinates($request, $data);
		if (array_key_exists('zip', $data) || $request->getZip() === null) {
			$this->applyZip($request, $data);
		}

		$request->setUpdatedAt(new \DateTime());

		return $this->requestMapper->update($request);
	}

	/**
	 * An in-person request needs the ZIP of where the help is needed; it decides the request's state
	 * unless someone reassigned it. The exact address and point stay optional.
	 *
	 * @param array<string, mixed> $data
	 * @throws InvalidStateException when the ZIP is missing or not a ZIP of a US state
	 */
	private function applyZip(Request $request, array $data): void {
		$given = $data['zip'] ?? null;
		if ($given === null || $given === '') {
			throw new InvalidStateException('A ZIP code is required: the 5-digit ZIP of where the help is needed');
		}
		$zip = ZipCenters::normalize($given);
		if ($zip === null) {
			throw new InvalidStateException('ZIP code must be 5 digits (or ZIP+4) of a US state, for example 97055');
		}
		$request->setZip($zip);
		$request->setChapter($this->presenter->chapterOf($request));
	}

	/**
	 * Sets latitude/longitude from explicit values, or from "lat, lng" location text
	 * when no explicit values are given. Free-text addresses leave coordinates unset.
	 *
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException if explicit coordinates are not numeric or out of range
	 */
	private function applyCoordinates(Request $request, array $data): void {
		$lat = $data['latitude'] ?? null;
		$lng = $data['longitude'] ?? null;

		if ($lat !== null && $lng !== null) {
			if (!is_numeric($lat) || !is_numeric($lng)) {
				throw new \InvalidArgumentException('Latitude and longitude must be numbers');
			}
			$point = Coordinates::validate((float)$lat, (float)$lng);
			if ($point === null) {
				throw new \InvalidArgumentException('Coordinates are out of range');
			}
		} else {
			$point = Coordinates::parseText($request->getLocation());
		}

		if ($point !== null) {
			$request->setLatitude($point[0]);
			$request->setLongitude($point[1]);
		}
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function completeRequest(int $id, string $userId, ?int $selectedVolunteerId = null): Request {
		$request = $this->requestMapper->find($id);

		if ($request->getRequesterId() !== $userId) {
			throw new NotAuthorizedException('Not authorized to complete this request');
		}
		if (!in_array($request->getStatus(), ['open', 'in_progress'], true)) {
			throw new InvalidStateException('This request is already finished');
		}

		// Every accepted volunteer completes together and is credited for the hours they
		// proposed (hour for hour, never split). Volunteers are looked up through this
		// request, so another request's offers can never be completed from here.
		$accepted = array_values(array_filter(
			$this->volunteerMapper->findByRequest($id),
			fn ($volunteer) => $volunteer->getStatus() === 'accepted',
		));
		if ($accepted === []) {
			throw new InvalidStateException('Accept at least one volunteer before completing the request');
		}
		if ($selectedVolunteerId !== null
			&& !in_array($selectedVolunteerId, array_map(fn ($volunteer) => $volunteer->getId(), $accepted), true)) {
			throw new InvalidStateException('That volunteer has not been accepted for this request');
		}

		foreach ($accepted as $volunteer) {
			$volunteer->setStatus('completed');
			$this->volunteerMapper->update($volunteer);

			$this->statsMapper->incrementStats(
				$volunteer->getVolunteerId(),
				$request->getCategoryId(),
				(float)$volunteer->getProposedHours()
			);
		}

		$request->setStatus('completed');
		$request->setUpdatedAt(new \DateTime());

		return $this->requestMapper->update($request);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function cancelRequest(int $id, string $userId): Request {
		$request = $this->requestMapper->find($id);

		if ($request->getRequesterId() !== $userId) {
			throw new NotAuthorizedException('Not authorized to cancel this request');
		}
		if (!in_array($request->getStatus(), ['open', 'in_progress'], true)) {
			throw new InvalidStateException('This request is already finished');
		}

		$request->setStatus('cancelled');
		$request->setUpdatedAt(new \DateTime());

		return $this->requestMapper->update($request);
	}
}
