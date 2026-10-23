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
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/volunteers/{id}', requirements: ['id' => '\\d+'])]
	public function withdraw(int $id): DataResponse {
		try {
			$this->volunteerService->withdraw($id, $this->userId);
			return new DataResponse(['success' => true]);
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/volunteers/{id}/accept', requirements: ['id' => '\\d+'])]
	public function accept(int $id): DataResponse {
		try {
			$volunteer = $this->volunteerService->accept($id, $this->userId);
			return new DataResponse($volunteer->jsonSerialize());
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/volunteers/{id}/decline', requirements: ['id' => '\\d+'])]
	public function decline(int $id): DataResponse {
		try {
			$volunteer = $this->volunteerService->decline($id, $this->userId);
			return new DataResponse($volunteer->jsonSerialize());
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/volunteers/my')]
	public function myOffers(): DataResponse {
		$offers = $this->volunteerService->getMyVolunteerOffers($this->userId);
		return new DataResponse(array_map(fn ($v) => $v->jsonSerialize(), $offers));
	}
}
