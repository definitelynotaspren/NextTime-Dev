<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\CommentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class CommentController extends OCSController {

	private CommentService $commentService;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		CommentService $commentService,
		?string $userId,
	) {
		parent::__construct($appName, $request);
		$this->commentService = $commentService;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/requests/{requestId}/comments')]
	public function create(int $requestId): DataResponse {
		$data = [
			'comment' => $this->request->getParam('comment'),
			'parentId' => $this->request->getParam('parentId')
				? (int)$this->request->getParam('parentId') : null,
		];

		try {
			$comment = $this->commentService->create($requestId, $this->userId, $data);
			return new DataResponse($comment->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/comments/{id}')]
	public function delete(int $id): DataResponse {
		try {
			$this->commentService->delete($id, $this->userId);
			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}
}
