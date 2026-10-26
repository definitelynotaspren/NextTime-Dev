<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Schema\LayerException;
use OCA\TimeBank\Schema\Viewer;
use OCA\TimeBank\Service\DeskBridge;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\LayerService;
use OCA\TimeBank\Service\StateReassignment;
use OCA\TimeBank\Service\ViewerResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Organizer desk: rows of each layer, assignment, and the public feed.
 *
 * Every response is built by LayerService, which filters fields by visibility.
 */
class LayerController extends Controller {
	use CurrentUser;
	use RequestParams;
	use ServiceErrors;

	private LayerService $layerService;
	private ViewerResolver $viewers;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		LayerService $layerService,
		ViewerResolver $viewers,
		private StateReassignment $reassignment,
		private DeskBridge $bridge,
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
			'assigned' => $this->textParam('assigned'),
			'source' => $this->textParam('source'),
		]));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/layers/{layer}/rows', requirements: ['layer' => '[a-z_]+'])]
	public function create(string $layer): DataResponse {
		// Field values only: not the route's layer and id, nor framework-internal parameters such as _route.
		$input = [];
		foreach ($this->request->getParams() as $key => $value) {
			if (is_string($key) && $key !== 'layer' && $key !== 'id' && !str_starts_with($key, '_')) {
				$input[$key] = $value;
			}
		}

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
			$this->optionalTextParam('status'),
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

	/**
	 * Moves the row to another state (`chapter`: a chapter slug), or back to its ZIP's state when
	 * `chapter` is empty. Facilitators of the old or new state, national organizers, admins; logged.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/layers/{layer}/rows/{id}/state', requirements: ['layer' => '[a-z_]+', 'id' => '\\d+'])]
	public function state(string $layer, int $id): DataResponse {
		try {
			return new DataResponse($this->reassignment->moveDeskRow($layer, $id, $this->uid(), $this->optionalTextParam('chapter')));
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/**
	 * Puts a need on the request board. The facilitator types the public title and description; the
	 * ZIP comes from the row and nothing else does. See DeskBridge.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 50, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/layers/{layer}/rows/{id}/board', requirements: ['layer' => '[a-z_]+', 'id' => '\\d+'])]
	public function board(string $layer, int $id): DataResponse {
		try {
			return $this->run(fn (Viewer $viewer) => $this->bridge->putOnBoard($viewer, $layer, $id, [
				'title' => $this->request->getParam('title'),
				'description' => $this->request->getParam('description'),
				'categoryId' => $this->request->getParam('categoryId'),
				'hoursBudget' => $this->request->getParam('hoursBudget'),
				'priority' => $this->request->getParam('priority'),
			]), Http::STATUS_CREATED);
		} catch (InvalidStateException $e) {
			return $this->errorResponse($e);
		}
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
			$this->optionalTextParam('layer'),
			$this->optionalTextParam('source'),
		));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/layers/{layer}/feed', requirements: ['layer' => '[a-z_]+'])]
	public function feed(string $layer): DataResponse {
		return $this->run(fn (Viewer $viewer) => $this->layerService->publicFeed($viewer, $layer));
	}

	/**
	 * @param callable(Viewer): array $action
	 * @param Http::STATUS_* $status
	 */
	private function run(callable $action, int $status = Http::STATUS_OK): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$viewer = $this->viewers->forUser($this->uid());
			return new DataResponse($action($viewer), $status);
		} catch (LayerException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->status());
		}
	}
}
