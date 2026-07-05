<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\BalanceService;
use OCA\TimeBank\Service\TransactionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;

class BalanceController extends Controller {

	private BalanceService $balanceService;
	private TransactionService $transactionService;
	private IGroupManager $groupManager;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		BalanceService $balanceService,
		TransactionService $transactionService,
		IGroupManager $groupManager,
		?string $userId,
	) {
		parent::__construct($appName, $request);
		$this->balanceService = $balanceService;
		$this->transactionService = $transactionService;
		$this->groupManager = $groupManager;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/balance/my')]
	public function my(): DataResponse {
		$balance = $this->balanceService->getBalance($this->userId);
		return new DataResponse($balance->jsonSerialize());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/balance/all')]
	public function all(): DataResponse {
		$limit = (int)($this->request->getParam('limit') ?? 100);
		$offset = (int)($this->request->getParam('offset') ?? 0);

		$balances = $this->balanceService->getAllBalances($limit, $offset);

		return new DataResponse(array_map(fn ($b) => $b->jsonSerialize(), $balances));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/balance/adjust')]
	public function adjust(): DataResponse {
		if (!$this->groupManager->isAdmin($this->userId)) {
			return new DataResponse(['error' => 'Unauthorized'], Http::STATUS_FORBIDDEN);
		}

		$targetUserId = $this->request->getParam('userId');
		$hours = (float)$this->request->getParam('hours');
		$reason = $this->request->getParam('reason');

		try {
			if ($hours > 0) {
				$this->balanceService->addHours($targetUserId, $hours);
			} else {
				$this->balanceService->deductHours($targetUserId, abs($hours));
			}

			$this->transactionService->recordAdjustment($targetUserId, $hours, $reason, $this->userId);

			$balance = $this->balanceService->getBalance($targetUserId);
			return new DataResponse($balance->jsonSerialize());
		} catch (\Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
