<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\RequestService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class RequestController extends OCSController {

	private RequestService $requestService;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		RequestService $requestService,
		?string $userId,
	) {
		parent::__construct($appName, $request);
		$this->requestService = $requestService;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/requests')]
	public function index(): DataResponse {
		$filters = [
			'status' => $this->request->getParam('status'),
			'categoryId' => $this->request->getParam('categoryId'),
			'priority' => $this->request->getParam('priority'),
		];

		$filters = array_filter($filters, fn ($v) => $v !== null);

		$limit = (int)($this->request->getParam('limit') ?? 50);
		$offset = (int)($this->request->getParam('offset') ?? 0);

		$data = $this->requestService->getRequests($filters, $limit, $offset);

		return new DataResponse($data);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/requests/{id}')]
	public function show(int $id): DataResponse {
		try {
			$data = $this->requestService->getRequestDetails($id);
			return new DataResponse($data);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/requests')]
	public function create(): DataResponse {
		// Validate required fields
		$title = $this->request->getParam('title');
		$description = $this->request->getParam('description');
		$categoryId = $this->request->getParam('categoryId');
		$hoursBudget = $this->request->getParam('hoursBudget');
		$priority = $this->request->getParam('priority', 'normal');

		if (empty($title) || strlen(trim($title)) < 5) {
			return new DataResponse(['error' => 'Title must be at least 5 characters'], Http::STATUS_BAD_REQUEST);
		}

		if (strlen($title) > 200) {
			return new DataResponse(['error' => 'Title cannot exceed 200 characters'], Http::STATUS_BAD_REQUEST);
		}

		if (empty($description) || strlen(trim($description)) < 20) {
			return new DataResponse(['error' => 'Description must be at least 20 characters'], Http::STATUS_BAD_REQUEST);
		}

		if (!$categoryId || !is_numeric($categoryId)) {
			return new DataResponse(['error' => 'Valid category ID is required'], Http::STATUS_BAD_REQUEST);
		}

		if (!$hoursBudget || !is_numeric($hoursBudget) || (float)$hoursBudget <= 0) {
			return new DataResponse(['error' => 'Hours budget must be a positive number'], Http::STATUS_BAD_REQUEST);
		}

		if ((float)$hoursBudget > 1000) {
			return new DataResponse(['error' => 'Hours budget cannot exceed 1000'], Http::STATUS_BAD_REQUEST);
		}

		if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
			return new DataResponse(['error' => 'Priority must be low, normal, high, or urgent'], Http::STATUS_BAD_REQUEST);
		}

		$data = [
			'title' => trim($title),
			'description' => trim($description),
			'categoryId' => (int)$categoryId,
			'hoursBudget' => (float)$hoursBudget,
			'priority' => $priority,
			'deadline' => $this->request->getParam('deadline'),
			'location' => $this->request->getParam('location'),
		];

		try {
			$request = $this->requestService->createRequest($this->userId, $data);
			return new DataResponse($request->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/requests/{id}')]
	public function update(int $id): DataResponse {
		$data = [
			'title' => $this->request->getParam('title'),
			'description' => $this->request->getParam('description'),
			'hoursBudget' => $this->request->getParam('hoursBudget')
				? (float)$this->request->getParam('hoursBudget') : null,
			'priority' => $this->request->getParam('priority'),
			'deadline' => $this->request->getParam('deadline'),
			'location' => $this->request->getParam('location'),
		];

		$data = array_filter($data, fn ($v) => $v !== null);

		try {
			$request = $this->requestService->updateRequest($id, $this->userId, $data);
			return new DataResponse($request->jsonSerialize());
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/requests/{id}/complete')]
	public function complete(int $id): DataResponse {
		$volunteerId = (int)$this->request->getParam('volunteerId');

		try {
			$request = $this->requestService->completeRequest($id, $this->userId, $volunteerId);
			return new DataResponse($request->jsonSerialize());
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/requests/{id}/cancel')]
	public function cancel(int $id): DataResponse {
		try {
			$request = $this->requestService->cancelRequest($id, $this->userId);
			return new DataResponse($request->jsonSerialize());
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/requests/my')]
	public function myRequests(): DataResponse {
		$filters = ['requesterId' => $this->userId];
		$data = $this->requestService->getRequests($filters);

		return new DataResponse($data);
	}
}
