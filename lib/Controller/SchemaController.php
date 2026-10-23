<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Schema\SchemaSpec;
use OCA\TimeBank\Service\ApproverPolicy;
use OCA\TimeBank\Service\CommentService;
use OCA\TimeBank\Service\ViewerResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * The layer and field definitions, for organizers and facilitators.
 */
class SchemaController extends Controller {

	private ViewerResolver $viewers;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		ViewerResolver $viewers,
		private CommentService $comments,
		private IGroupManager $groups,
		private ApproverPolicy $policy,
		?string $userId,
	) {
		parent::__construct($appName, $request);
		$this->viewers = $viewers;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/schema')]
	public function show(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$viewer = $this->viewers->forUser($this->userId);
		if (!$viewer->isStaff()) {
			return new DataResponse(['error' => 'Organizer or facilitator role required'], Http::STATUS_FORBIDDEN);
		}

		$chapters = [];
		foreach (SchemaSpec::chapters() as $slug => $chapter) {
			$chapters[] = ['slug' => $slug, 'zips' => $chapter['zips']];
		}

		return new DataResponse([
			'systemFields' => SchemaSpec::systemFields(),
			'layers' => SchemaSpec::layers(),
			'lookups' => SchemaSpec::lookups(),
			'chapters' => $chapters,
			'visibilityLevels' => ['public', 'facilitator', 'chapter', 'organizer'],
		]);
	}

	/**
	 * The caller's own roles, so the UI can hide links they cannot use.
	 * This is a convenience only; every endpoint still enforces its own rules.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/roles')]
	public function roles(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$viewer = $this->viewers->forUser($this->userId);

		return new DataResponse([
			'organizer' => $viewer->organizer,
			'facilitator' => $viewer->facilitator,
			'moderator' => $this->comments->isModerator($this->userId),
			'admin' => $this->groups->isAdmin($this->userId),
			'fallbackApprover' => $this->groups->isInGroup($this->userId, $this->policy->fallbackGroup()),
		]);
	}
}
