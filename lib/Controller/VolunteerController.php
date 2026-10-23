<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\VolunteerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class VolunteerController extends Controller {
	use ServiceErrors;

	private VolunteerService $volunteerService;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		VolunteerService $volunteerService,
		?string $userId,
	) {
		parent::__construct($appName, $request);
		$this->volunteerService = $volunteerService;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/requests/{requestId}/volunteer', requirements: ['requestId' => '\\d+'])]
	public function offer(int $requestId): DataResponse {
		$data = [
			'proposedHours' => (float)$this->request->getParam('proposedHours'),
			'message' => $this->request->getParam('message'),
		];

		try {
			$volunteer = $this->volunteerService->offer($requestId, $this->userId, $data);
			return new DataResponse($volunteer->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/volunteers/{id}', requirements: ['id' => '\\d+'])]
	public function withdraw(int $id): DataResponse {
		// Withdrawing an accepted offer needs a reason; it goes into the log of withdrawn help.
		$reason = $this->request->getParam('reason');

		try {
			$this->volunteerService->withdraw($id, $this->userId, is_string($reason) ? $reason : null);
			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/volunteers/{id}/accept', requirements: ['id' => '\\d+'])]
	public function accept(int $id): DataResponse {
		try {
			$volunteer = $this->volunteerService->accept($id, $this->userId);
			return new DataResponse($volunteer->jsonSerialize());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/volunteers/{id}/decline', requirements: ['id' => '\\d+'])]
	public function decline(int $id): DataResponse {
		try {
			$volunteer = $this->volunteerService->decline($id, $this->userId);
			return new DataResponse($volunteer->jsonSerialize());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/volunteers/my')]
	public function myOffers(): DataResponse {
		return new DataResponse($this->volunteerService->getMyOffersWithRequests($this->userId));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/withdrawals/my')]
	public function myWithdrawals(): DataResponse {
		$entries = $this->volunteerService->getMyWithdrawals($this->userId);
		return new DataResponse(array_map(fn ($w) => $w->jsonSerialize(), $entries));
	}
}
