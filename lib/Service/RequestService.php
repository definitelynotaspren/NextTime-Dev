<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\CommentMapper;
use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Db\UserStatsMapper;
use OCA\TimeBank\Db\VolunteerMapper;
use OCA\TimeBank\Geo\Coordinates;
use OCP\AppFramework\Db\DoesNotExistException;

class RequestService {

	private RequestMapper $requestMapper;
	private VolunteerMapper $volunteerMapper;
	private CommentMapper $commentMapper;
	private UserStatsMapper $statsMapper;

	public function __construct(
		RequestMapper $requestMapper,
		VolunteerMapper $volunteerMapper,
		CommentMapper $commentMapper,
		UserStatsMapper $statsMapper,
	) {
		$this->requestMapper = $requestMapper;
		$this->volunteerMapper = $volunteerMapper;
		$this->commentMapper = $commentMapper;
		$this->statsMapper = $statsMapper;
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{requests: array<array-key, mixed>, total: int, limit: int, offset: int}
	 */
	public function getRequests(array $filters = [], int $limit = 50, int $offset = 0): array {
		$requests = $this->requestMapper->findAll($filters, $limit, $offset);
		$total = $this->requestMapper->count($filters);

		$enrichedRequests = array_map(function ($request) {
			$volunteerCount = $this->volunteerMapper->countByRequest($request->getId());
			$commentCount = $this->commentMapper->countByRequest($request->getId());

			$data = $request->jsonSerialize();
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
	 * @return array{request: array<string, mixed>, volunteers: array<array-key, mixed>, comments: array<array-key, mixed>}
	 * @throws DoesNotExistException
	 */
	public function getRequestDetails(int $id): array {
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

		usort($enrichedVolunteers, function ($a, $b) {
			return $b['completedInCategory'] - $a['completedInCategory'];
		});

		return [
			'request' => $request->jsonSerialize(),
			'volunteers' => $enrichedVolunteers,
			'comments' => array_map(fn ($c) => $c->jsonSerialize(), $comments),
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function createRequest(string $userId, array $data): Request {
		$request = new Request();
		$request->setRequesterId($userId);
		$request->setTitle($data['title']);
		$request->setDescription($data['description']);
		$request->setCategoryId((int)$data['categoryId']);
		$request->setHoursBudget((string)$data['hoursBudget']);
		$request->setStatus('open');
		$request->setPriority($data['priority'] ?? 'normal');

		if (isset($data['deadline']) && $data['deadline']) {
			$request->setDeadline(new \DateTime($data['deadline']));
		}
		if (isset($data['location'])) {
			$request->setLocation($data['location']);
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
			throw new \Exception('Not authorized to update this request');
		}

		if (isset($data['title'])) {
			$request->setTitle($data['title']);
		}
		if (isset($data['description'])) {
			$request->setDescription($data['description']);
		}
		if (isset($data['hoursBudget'])) {
			$request->setHoursBudget((string)$data['hoursBudget']);
		}
		if (isset($data['priority'])) {
			$request->setPriority($data['priority']);
		}
		if (isset($data['deadline'])) {
			$request->setDeadline(new \DateTime($data['deadline']));
		}
		if (isset($data['location'])) {
			$request->setLocation($data['location']);
		}
		$this->applyCoordinates($request, $data);

		$request->setUpdatedAt(new \DateTime());

		return $this->requestMapper->update($request);
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
	public function completeRequest(int $id, string $userId, int $selectedVolunteerId): Request {
		$request = $this->requestMapper->find($id);

		if ($request->getRequesterId() !== $userId) {
			throw new \Exception('Not authorized to complete this request');
		}

		$request->setStatus('completed');
		$request->setUpdatedAt(new \DateTime());

		$volunteer = $this->volunteerMapper->find($selectedVolunteerId);
		$volunteer->setStatus('completed');
		$this->volunteerMapper->update($volunteer);

		$this->statsMapper->incrementStats(
			$volunteer->getVolunteerId(),
			$request->getCategoryId(),
			(float)$volunteer->getProposedHours()
		);

		return $this->requestMapper->update($request);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function cancelRequest(int $id, string $userId): Request {
		$request = $this->requestMapper->find($id);

		if ($request->getRequesterId() !== $userId) {
			throw new \Exception('Not authorized to cancel this request');
		}

		$request->setStatus('cancelled');
		$request->setUpdatedAt(new \DateTime());

		return $this->requestMapper->update($request);
	}
}
