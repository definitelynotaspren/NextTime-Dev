<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Import\ImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Importing from another NextTime database: preview, stage, then a second admin finalizes or cancels.
 * Admins only; the service checks the role and refuses the admin who staged an import when they try
 * to finalize it. Connection details arrive in the request body, are used for one connection, and are
 * never stored or logged.
 */
class ImportController extends Controller {
	use ServiceErrors;
	use CurrentUser;
	use RequestParams;

	public function __construct(
		string $appName,
		IRequest $request,
		private ImportService $imports,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/** What an import from this source would do. Writes nothing. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/import/preview')]
	public function preview(): DataResponse {
		try {
			return new DataResponse($this->imports->preview($this->uid(), $this->textParam('label'), $this->textParam('kind'), $this->connection()));
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Holds the rows until a second admin finalizes. 201 with the import. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/import/stage')]
	public function stage(): DataResponse {
		try {
			$import = $this->imports->stage($this->uid(), $this->textParam('label'), $this->textParam('kind'), $this->connection());

			return new DataResponse($import->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Recent imports, newest first, each with whether this admin may finalize it. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/import/runs')]
	public function runs(): DataResponse {
		try {
			$me = $this->uid();
			$runs = array_map(
				static fn (array $run): array => $run + ['canFinalize' => $run['status'] === 'staged' && $run['stagedBy'] !== $me],
				$this->imports->recent($me),
			);

			return new DataResponse(['imports' => $runs]);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** One import with its admin-only report. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/import/runs/{id}', requirements: ['id' => '\d+'])]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->imports->detail($id, $this->uid()));
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Writes a staged import; refused for the admin who staged it. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/import/runs/{id}/finalize', requirements: ['id' => '\d+'])]
	public function finalize(int $id): DataResponse {
		try {
			return new DataResponse($this->imports->finalize($id, $this->uid())->jsonSerialize());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Drops a staged import. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/import/runs/{id}/cancel', requirements: ['id' => '\d+'])]
	public function cancel(int $id): DataResponse {
		try {
			return new DataResponse($this->imports->cancel($id, $this->uid())->jsonSerialize());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/**
	 * The connection details from the body. The password is read exactly as typed, not trimmed.
	 *
	 * @return array<string, mixed>
	 */
	private function connection(): array {
		$password = $this->request->getParam('password');

		return [
			'host' => $this->textParam('host'),
			'port' => $this->textParam('port'),
			'database' => $this->textParam('database'),
			'user' => $this->textParam('user'),
			'password' => is_string($password) ? $password : '',
			'prefix' => $this->optionalTextParam('prefix') ?? 'oc_',
			'file' => $this->textParam('file'),
		];
	}
}
