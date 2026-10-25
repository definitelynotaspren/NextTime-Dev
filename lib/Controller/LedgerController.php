<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\Input;
use OCA\TimeBank\Service\TransactionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class LedgerController extends Controller {
	use CurrentUser;

	private TransactionService $transactionService;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		TransactionService $transactionService,
		?string $userId,
	) {
		parent::__construct($appName, $request);
		$this->transactionService = $transactionService;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/ledger')]
	public function index(): DataResponse {
		$limit = Input::limit($this->request->getParam('limit'), 50);
		$offset = Input::offset($this->request->getParam('offset'));

		$data = $this->transactionService->getPublicLedger($limit, $offset);

		return new DataResponse($data);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/ledger/my')]
	public function myTransactions(): DataResponse {
		$limit = Input::limit($this->request->getParam('limit'), 50);
		$offset = Input::offset($this->request->getParam('offset'));

		$data = $this->transactionService->getUserTransactions($this->uid(), $limit, $offset);

		return new DataResponse($data);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/ledger/user/{userId}')]
	public function userTransactions(string $userId): DataResponse {
		$limit = Input::limit($this->request->getParam('limit'), 50);
		$offset = Input::offset($this->request->getParam('offset'));

		$data = $this->transactionService->getUserTransactions($userId, $limit, $offset);

		return new DataResponse($data);
	}
}
