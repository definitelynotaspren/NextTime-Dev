<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\RequestService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class RequestController extends Controller {

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
	public function index(): JSONResponse {
		$filters = [
			'status' => $this->request->getParam('status'),
			'categoryId' => $this->request->getParam('categoryId'),
			'priority' => $this->request->getParam('priority'),
		];

		$filters = array_filter($filters, fn ($v) => $v !== null);

		$limit = (int)($this->request->getParam('limit') ?? 50);
		$offset = (int)($this->request->getParam('offset') ?? 0);

		$data = $this->requestService->getRequests($filters, $limit, $offset);

		return new JSONResponse($data);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/requests/{id}')]
	public function show(int $id): JSONResponse {
		try {
			$data = $this->requestService->getRequestDetails($id);
			return new JSONResponse($data);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/requests')]
	public function create(): JSONResponse {
		// Validate required fields
		$title = $this->request->getParam('title');
		$description = $this->request->getParam('description');
		$categoryId = $this->request->getParam('categoryId');
		$hoursBudget = $this->request->getParam('hoursBudget');
		$priority = $this->request->getParam('priority', 'normal');

		if (empty($title) || strlen(trim($title)) < 5) {
			return new JSONResponse(['error' => 'Title must be at least 5 characters'], Http::STATUS_BAD_REQUEST);
		}

		if (strlen($title) > 200) {
			return new JSONResponse(['error' => 'Title cannot exceed 200 characters'], Http::STATUS_BAD_REQUEST);
		}

		if (empty($description) || strlen(trim($description)) < 20) {
			return new JSONResponse(['error' => 'Description must be at least 20 characters'], Http::STATUS_BAD_REQUEST);
		}

		if (!$categoryId || !is_numeric($categoryId)) {
			return new JSONResponse(['error' => 'Valid category ID is required'], Http::STATUS_BAD_REQUEST);
		}

		if (!$hoursBudget || !is_numeric($hoursBudget) || (float)$hoursBudget <= 0) {
			return new JSONResponse(['error' => 'Hours budget must be a positive number'], Http::STATUS_BAD_REQUEST);
		}

		if ((float)$hoursBudget > 1000) {
			return new JSONResponse(['error' => 'Hours budget cannot exceed 1000'], Http::STATUS_BAD_REQUEST);
		}

		if (!in_array($priority, ['urgent', 'normal', 'flexible'], true)) {
			return new JSONResponse(['error' => 'Priority must be urgent, normal, or flexible'], Http::STATUS_BAD_REQUEST);
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
			return new JSONResponse($request->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/requests/{id}')]
	public function update(int $id): JSONResponse {
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
			return new JSONResponse($request->jsonSerialize());
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/requests/{id}/complete')]
	public function complete(int $id): JSONResponse {
		$volunteerId = (int)$this->request->getParam('volunteerId');

		try {
			$request = $this->requestService->completeRequest($id, $this->userId, $volunteerId);
			return new JSONResponse($request->jsonSerialize());
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/requests/{id}/cancel')]
	public function cancel(int $id): JSONResponse {
		try {
			$request = $this->requestService->cancelRequest($id, $this->userId);
			return new JSONResponse($request->jsonSerialize());
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/requests/my')]
	public function myRequests(): JSONResponse {
		$filters = ['requesterId' => $this->userId];
		$data = $this->requestService->getRequests($filters);

		return new JSONResponse($data);
	}
}
