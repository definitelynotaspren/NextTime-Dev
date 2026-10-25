<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\AdjustmentService;
use OCA\TimeBank\Service\GovernanceLog;
use OCA\TimeBank\Service\Input;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Manual balance adjustments (a second person approves large ones) and the public
 * governance log. This replaces the old single-step /api/balance/adjust.
 */
class AdjustmentController extends Controller {
	use ServiceErrors;
	use CurrentUser;

	public function __construct(
		string $appName,
		IRequest $request,
		private AdjustmentService $adjustments,
		private GovernanceLog $log,
		private IGroupManager $groups,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/** Admins and facilitators: recent adjustments, what each may do, and the threshold. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/adjustments')]
	public function index(): DataResponse {
		try {
			return new DataResponse([
				'adjustments' => $this->adjustments->listFor($this->uid()),
				'threshold' => $this->adjustments->getThreshold(),
				'canPropose' => $this->groups->isAdmin($this->uid()),
				'expiryDays' => AdjustmentService::EXPIRY_DAYS,
			]);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Small adjustments are applied at once (201, status approved); larger ones stay pending. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/adjustments')]
	public function propose(): DataResponse {
		try {
			$adjustment = $this->adjustments->propose(
				$this->uid(),
				trim((string)$this->request->getParam('userId')),
				(float)$this->request->getParam('hours'),
				(string)$this->request->getParam('reason'),
			);
			return new DataResponse($adjustment->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/adjustments/{id}/approve', requirements: ['id' => '\\d+'])]
	public function approve(int $id): DataResponse {
		try {
			return new DataResponse($this->adjustments->approve($id, $this->uid())->jsonSerialize());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/adjustments/{id}/reject', requirements: ['id' => '\\d+'])]
	public function reject(int $id): DataResponse {
		try {
			return new DataResponse($this->adjustments->reject($id, $this->uid(), (string)$this->request->getParam('note'))->jsonSerialize());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/adjustments/{id}/withdraw', requirements: ['id' => '\\d+'])]
	public function withdraw(int $id): DataResponse {
		try {
			return new DataResponse($this->adjustments->withdraw($id, $this->uid())->jsonSerialize());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Admins only; logged publicly with the reason. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/settings/adjustment-threshold')]
	public function setThreshold(): DataResponse {
		try {
			$hours = $this->adjustments->setThreshold(
				$this->uid(),
				(float)$this->request->getParam('hours'),
				(string)$this->request->getParam('reason'),
			);
			return new DataResponse(['threshold' => $hours]);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Every member can read the log of decisions. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/governance')]
	public function governance(): DataResponse {
		return new DataResponse($this->log->getPage(
			Input::limit($this->request->getParam('limit')),
			Input::offset($this->request->getParam('offset')),
		));
	}
}
