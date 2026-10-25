<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Db\IntakeLogMapper;
use OCA\TimeBank\Intake\FormsIntake;
use OCA\TimeBank\Schema\SchemaSpec;
use OCA\TimeBank\Service\NotAuthorizedException;
use OCA\TimeBank\Service\ViewerResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Choosing which Nextcloud Forms feed the organizer desk, and watching what came in.
 * For admins and organizers.
 */
class IntakeController extends Controller {
	use ServiceErrors;
	use CurrentUser;

	public function __construct(
		string $appName,
		IRequest $request,
		private FormsIntake $intake,
		private IntakeLogMapper $log,
		private ViewerResolver $viewers,
		private IGroupManager $groups,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/intake')]
	public function index(): DataResponse {
		try {
			$this->requireManager();
			$layers = [];
			foreach (SchemaSpec::layers() as $name => $spec) {
				if (!empty($spec['form']) && empty($spec['aggregate_only'])) {
					$layers[] = ['name' => $name, 'title' => $spec['title']];
				}
			}

			return new DataResponse([
				'formsInstalled' => $this->intake->formsAvailable(),
				'forms' => $this->intake->addedForms(),
				'layers' => $layers,
				'recent' => array_map(fn ($e) => $e->jsonSerialize(), $this->log->findRecent()),
			]);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/**
	 * Find the form a pasted link points to, and show how it would map. Adds nothing.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/intake/forms/lookup')]
	public function lookup(): DataResponse {
		try {
			$this->requireManager();
			$layer = (string)($this->request->getParam('layer') ?? 'needs_offers');
			$form = $this->intake->findByLink((string)$this->request->getParam('link'), $this->request->getServerHost());

			return new DataResponse($form + [
				'layer' => $layer,
				'preview' => $this->intake->preview($form['id'], $layer),
				'alreadyAdded' => in_array($form['id'], array_column($this->intake->enabled(), 'formId'), true),
			]);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/intake/forms')]
	public function setForms(): DataResponse {
		try {
			$this->requireManager();
			$forms = $this->request->getParam('forms');
			$this->intake->setEnabled($this->uid(), is_array($forms) ? array_values($forms) : []);

			return new DataResponse(['enabled' => $this->intake->enabled()]);
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/intake/forms/{id}/preview', requirements: ['id' => '\\d+'])]
	public function preview(int $id): DataResponse {
		try {
			$this->requireManager();

			return new DataResponse($this->intake->preview($id, (string)($this->request->getParam('layer') ?? 'needs_offers')));
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** "Check now": the same thing the background job does every five minutes. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/intake/run')]
	public function run(): DataResponse {
		try {
			$this->requireManager();

			return new DataResponse($this->intake->sync());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/** Forget rejections so those submissions are looked at again (after fixing the form). */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/intake/retry')]
	public function retry(): DataResponse {
		try {
			$this->requireManager();
			$cleared = $this->log->clearRejected();

			return new DataResponse(['cleared' => $cleared] + $this->intake->sync());
		} catch (\Exception $e) {
			return $this->errorResponse($e);
		}
	}

	/**
	 * @throws NotAuthorizedException
	 */
	private function requireManager(): void {
		if ($this->userId === null
			|| (!$this->groups->isAdmin($this->uid()) && !$this->viewers->forUser($this->uid())->nationalOrganizer)) {
			throw new NotAuthorizedException('Admin or organizer role required');
		}
	}
}
