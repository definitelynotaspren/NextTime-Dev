<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\EarningService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;

class EarningController extends Controller {

	private EarningService $earningService;
	private IGroupManager $groupManager;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		EarningService $earningService,
		IGroupManager $groupManager,
		?string $userId,
	) {
		parent::__construct($appName, $request);
		$this->earningService = $earningService;
		$this->groupManager = $groupManager;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/earnings/claim')]
	public function claim(): JSONResponse {
		// Validate required fields
		$categoryId = $this->request->getParam('categoryId');
		$hoursClaimed = $this->request->getParam('hoursClaimed');
		$description = $this->request->getParam('description');

		if (!$categoryId || !is_numeric($categoryId)) {
			return new JSONResponse(['error' => 'Valid category ID is required'], Http::STATUS_BAD_REQUEST);
		}

		if (!$hoursClaimed || !is_numeric($hoursClaimed) || (float)$hoursClaimed <= 0) {
			return new JSONResponse(['error' => 'Hours claimed must be a positive number'], Http::STATUS_BAD_REQUEST);
		}

		if ((float)$hoursClaimed > 1000) {
			return new JSONResponse(['error' => 'Hours claimed cannot exceed 1000'], Http::STATUS_BAD_REQUEST);
		}

		if (empty($description) || strlen(trim($description)) < 10) {
			return new JSONResponse(['error' => 'Description must be at least 10 characters'], Http::STATUS_BAD_REQUEST);
		}

		if (strlen($description) > 5000) {
			return new JSONResponse(['error' => 'Description cannot exceed 5000 characters'], Http::STATUS_BAD_REQUEST);
		}

		$data = [
			'categoryId' => (int)$categoryId,
			'hoursClaimed' => (float)$hoursClaimed,
			'description' => trim($description),
			'evidenceFileId' => $this->request->getParam('evidenceFileId')
				? (int)$this->request->getParam('evidenceFileId') : null,
		];

		try {
			$earning = $this->earningService->submitClaim($this->userId, $data);
			return new JSONResponse($earning->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/earnings/my')]
	public function myClaims(): JSONResponse {
		$claims = $this->earningService->getUserClaims($this->userId);
		return new JSONResponse(array_map(fn ($c) => $c->jsonSerialize(), $claims));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/earnings/pending')]
	public function pending(): JSONResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
		}

		$claims = $this->earningService->getPendingClaims();
		return new JSONResponse(array_map(fn ($c) => $c->jsonSerialize(), $claims));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/earnings/voting')]
	public function voting(): JSONResponse {
		$claims = $this->earningService->getVotingClaims();
		return new JSONResponse($claims);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/earnings/{id}/approve')]
	public function approve(int $id): JSONResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$earning = $this->earningService->approveClaim($id, $this->userId);
			return new JSONResponse($earning->jsonSerialize());
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/earnings/{id}/reject')]
	public function reject(int $id): JSONResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
		}

		$reason = $this->request->getParam('reason');

		if (empty($reason) || strlen(trim($reason)) < 10) {
			return new JSONResponse(['error' => 'Rejection reason must be at least 10 characters'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$earning = $this->earningService->rejectClaim($id, $this->userId, trim($reason));
			return new JSONResponse($earning->jsonSerialize());
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/earnings/{id}/send-to-vote')]
	public function sendToVote(int $id): JSONResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$earning = $this->earningService->sendToVoting($id, $this->userId);
			return new JSONResponse($earning->jsonSerialize());
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/earnings/{id}/vote')]
	public function vote(int $id): JSONResponse {
		$vote = $this->request->getParam('vote');
		$comment = $this->request->getParam('comment');

		if (!in_array($vote, ['approve', 'reject', 'abstain'], true)) {
			return new JSONResponse(['error' => 'Vote must be approve, reject, or abstain'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->earningService->recordVote($id, $this->userId, $vote, $comment ? trim($comment) : null);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
