<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Schema\LayerException;
use OCA\TimeBank\Schema\Viewer;
use OCA\TimeBank\Service\LayerService;
use OCA\TimeBank\Service\ViewerResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Organizer desk: rows of each layer, assignment, and the public feed.
 *
 * Every response is built by LayerService, which filters fields by visibility.
 */
class LayerController extends Controller {

	private LayerService $layerService;
	private ViewerResolver $viewers;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		LayerService $layerService,
		ViewerResolver $viewers,
		?string $userId,
	) {
		parent::__construct($appName, $request);
		$this->layerService = $layerService;
		$this->viewers = $viewers;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/layers/{layer}/rows', requirements: ['layer' => '[a-z_]+'])]
	public function rows(string $layer): DataResponse {
		return $this->run(fn (Viewer $viewer) => $this->layerService->listRows($viewer, $layer, [
			'assigned' => $this->request->getParam('assigned'),
			'source' => $this->request->getParam('source'),
		]));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/layers/{layer}/rows', requirements: ['layer' => '[a-z_]+'])]
	public function create(string $layer): DataResponse {
		$input = $this->request->getParams();
		unset($input['layer'], $input['id']);
		// Drop framework-internal parameters such as _route.
		$input = array_filter($input, fn ($key) => !str_starts_with((string)$key, '_'), ARRAY_FILTER_USE_KEY);

		return $this->run(
			fn (Viewer $viewer) => $this->layerService->create($viewer, $layer, $input),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/layers/{layer}/rows/{id}/assign', requirements: ['layer' => '[a-z_]+', 'id' => '\\d+'])]
	public function assign(string $layer, int $id): DataResponse {
		return $this->run(fn (Viewer $viewer) => $this->layerService->assign(
			$viewer,
			$layer,
			$id,
			$this->request->getParam('status'),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/layers/{layer}/rows/{id}/assign', requirements: ['layer' => '[a-z_]+', 'id' => '\\d+'])]
	public function unassign(string $layer, int $id): DataResponse {
		return $this->run(fn (Viewer $viewer) => $this->layerService->unassign($viewer, $layer, $id));
	}

	/** publish=yes puts the row in the public feed (public fields, rounded point); publish=no takes it down. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/layers/{layer}/rows/{id}/publish', requirements: ['layer' => '[a-z_]+', 'id' => '\\d+'])]
	public function publish(string $layer, int $id): DataResponse {
		return $this->run(fn (Viewer $viewer) => $this->layerService->setPublished(
			$viewer,
			$layer,
			$id,
			$this->request->getParam('publish') === 'yes',
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/layers/{layer}/rows/{id}/status', requirements: ['layer' => '[a-z_]+', 'id' => '\\d+'])]
	public function status(string $layer, int $id): DataResponse {
		return $this->run(fn (Viewer $viewer) => $this->layerService->setAssignmentStatus(
			$viewer,
			$layer,
			$id,
			(string)$this->request->getParam('status'),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/assignments')]
	public function assignments(): DataResponse {
		return $this->run(fn (Viewer $viewer) => $this->layerService->myAssignments(
			$viewer,
			$this->request->getParam('layer'),
			$this->request->getParam('source'),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/layers/{layer}/feed', requirements: ['layer' => '[a-z_]+'])]
	public function feed(string $layer): DataResponse {
		return $this->run(fn (Viewer $viewer) => $this->layerService->publicFeed($viewer, $layer));
	}

	/**
	 * @param callable(Viewer): mixed $action
	 */
	private function run(callable $action, int $status = Http::STATUS_OK): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$viewer = $this->viewers->forUser($this->userId);
			return new DataResponse($action($viewer), $status);
		} catch (LayerException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getCode());
		}
	}
}
