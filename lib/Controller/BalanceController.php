<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\BalanceService;
use OCA\TimeBank\Service\Input;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;

class BalanceController extends Controller {
	use CurrentUser;

	private BalanceService $balanceService;
	private IGroupManager $groupManager;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		BalanceService $balanceService,
		IGroupManager $groupManager,
		?string $userId,
	) {
		parent::__construct($appName, $request);
		$this->balanceService = $balanceService;
		$this->groupManager = $groupManager;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/balance/my')]
	public function my(): DataResponse {
		$balance = $this->balanceService->getBalance($this->uid());
		return new DataResponse($balance->jsonSerialize());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/balance/all')]
	public function all(): DataResponse {
		$limit = Input::limit($this->request->getParam('limit'), 100);
		$offset = Input::offset($this->request->getParam('offset'));

		$balances = $this->balanceService->getAllBalances($limit, $offset);

		return new DataResponse(array_map(fn ($b) => $b->jsonSerialize(), $balances));
	}
}
