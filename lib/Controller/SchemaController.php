<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Schema\LayerException;
use OCA\TimeBank\Schema\SchemaSpec;
use OCA\TimeBank\Service\ViewerResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
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
		?string $userId,
	) {
		parent::__construct($appName, $request);
		$this->viewers = $viewers;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/schema')]
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
}
