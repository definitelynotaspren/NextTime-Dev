<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\CommentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class CommentController extends Controller {
	use ServiceErrors;
	use CurrentUser;
	use RequestParams;

	public function __construct(
		string $appName,
		IRequest $request,
		private CommentService $commentService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/requests/{requestId}/comments', requirements: ['requestId' => '\\d+'])]
	public function create(int $requestId): DataResponse {
		$data = [
			'comment' => $this->request->getParam('comment'),
			'parentId' => $this->request->getParam('parentId')
				? (int)$this->request->getParam('parentId') : null,
		];

		try {
			$comment = $this->commentService->create($requestId, $this->uid(), $data);
			return new DataResponse($comment->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Any member can flag a comment that is not theirs. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/comments/{id}/flag', requirements: ['id' => '\\d+'])]
	public function flag(int $id): DataResponse {
		try {
			$flag = $this->commentService->flag($id, $this->uid(), $this->optionalTextParam('reason'));
			return new DataResponse(['flagged' => true, 'id' => $flag->getId()], Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Moderators only: comments with counting flags, with reasons. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/comments/flagged')]
	public function flagged(): DataResponse {
		try {
			return new DataResponse($this->commentService->getFlagged($this->uid()));
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Moderators only: the flags stop counting and the comment is shown again. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/comments/{id}/dismiss', requirements: ['id' => '\\d+'])]
	public function dismiss(int $id): DataResponse {
		try {
			$this->commentService->dismissFlags($id, $this->uid());
			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Moderators only. Authors cannot delete their own comments. The removal is kept on record. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/comments/{id}', requirements: ['id' => '\\d+'])]
	public function delete(int $id): DataResponse {
		try {
			$this->commentService->remove($id, $this->uid());
			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}
}
