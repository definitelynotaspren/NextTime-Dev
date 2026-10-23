<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\CategoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;

class CategoryController extends Controller {
	use ServiceErrors;

	private CategoryService $categoryService;
	private IGroupManager $groupManager;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		CategoryService $categoryService,
		IGroupManager $groupManager,
		?string $userId,
	) {
		parent::__construct($appName, $request);
		$this->categoryService = $categoryService;
		$this->groupManager = $groupManager;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/categories')]
	public function index(): DataResponse {
		$categories = $this->categoryService->getAll();
		return new DataResponse(array_map(fn ($c) => $c->jsonSerialize(), $categories));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/categories/{id}', requirements: ['id' => '\\d+'])]
	public function show(int $id): DataResponse {
		try {
			$category = $this->categoryService->get($id);
			return new DataResponse($category->jsonSerialize());
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/categories')]
	public function create(): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
		}

		$data = [
			'name' => $this->request->getParam('name'),
			'description' => $this->request->getParam('description'),
			'earnRate' => $this->request->getParam('earnRate'),
			'icon' => $this->request->getParam('icon'),
		];

		try {
			$category = $this->categoryService->create($data, $this->userId);
			return new DataResponse($category->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/categories/{id}', requirements: ['id' => '\\d+'])]
	public function update(int $id): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
		}

		$data = [
			'name' => $this->request->getParam('name'),
			'description' => $this->request->getParam('description'),
			'earnRate' => $this->request->getParam('earnRate'),
			'icon' => $this->request->getParam('icon'),
		];

		$data = array_filter($data, fn ($v) => $v !== null);

		try {
			$category = $this->categoryService->update($id, $data, $this->userId);
			return new DataResponse($category->jsonSerialize());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/categories/{id}', requirements: ['id' => '\\d+'])]
	public function destroy(int $id): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->categoryService->delete($id, $this->userId);
			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** What would block deleting this category, for the admin screen. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/categories/{id}/usage', requirements: ['id' => '\\d+'])]
	public function usage(int $id): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new DataResponse($this->categoryService->usage($id));
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/categories/{id}/archive', requirements: ['id' => '\\d+'])]
	public function archive(int $id): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new DataResponse($this->categoryService->archive($id, $this->userId)->jsonSerialize());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/categories/{id}/restore', requirements: ['id' => '\\d+'])]
	public function restore(int $id): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new DataResponse($this->categoryService->restore($id, $this->userId)->jsonSerialize());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}
}
