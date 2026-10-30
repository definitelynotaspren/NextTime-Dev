<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\CommentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class CommentController extends Controller {

	private CommentService $commentService;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		private CommentService $commentService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/requests/{requestId}/comments')]
	public function create(int $requestId): JSONResponse {
		$data = [
			'comment' => $this->request->getParam('comment'),
			'parentId' => $this->request->getParam('parentId')
				? (int)$this->request->getParam('parentId') : null,
		];

		try {
			$comment = $this->commentService->create($requestId, $this->userId, $data);
			return new JSONResponse($comment->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Moderators only. Authors cannot delete their own comments. The removal is kept on record. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/comments/{id}')]
	public function delete(int $id): JSONResponse {
		try {
			$this->commentService->delete($id, $this->userId);
			return new JSONResponse(['success' => true]);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}
}
