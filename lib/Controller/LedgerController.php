<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\TransactionService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

class LedgerController extends OCSController {

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
	#[ApiRoute(verb: 'GET', url: '/api/ledger')]
	public function index(): DataResponse {
		$limit = (int)($this->request->getParam('limit') ?? 50);
		$offset = (int)($this->request->getParam('offset') ?? 0);

		$data = $this->transactionService->getPublicLedger($limit, $offset);

		return new DataResponse($data);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/ledger/my')]
	public function myTransactions(): DataResponse {
		$limit = (int)($this->request->getParam('limit') ?? 50);
		$offset = (int)($this->request->getParam('offset') ?? 0);

		$data = $this->transactionService->getUserTransactions($this->userId, $limit, $offset);

		return new DataResponse($data);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/ledger/user/{userId}')]
	public function userTransactions(string $userId): DataResponse {
		$limit = (int)($this->request->getParam('limit') ?? 50);
		$offset = (int)($this->request->getParam('offset') ?? 0);

		$data = $this->transactionService->getUserTransactions($userId, $limit, $offset);

		return new DataResponse($data);
	}
}
