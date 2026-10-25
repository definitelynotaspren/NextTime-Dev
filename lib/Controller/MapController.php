<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Geo\ZipCenters;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Read-only GeoJSON feeds for map clients such as uMap.
 *
 * Feeds are for logged-in members. Each request is placed at the center of its
 * ZIP area, never at its exact point, and only non-contact fields are included.
 */
class MapController extends Controller {

	private RequestMapper $requestMapper;

	public function __construct(
		string $appName,
		IRequest $request,
		RequestMapper $requestMapper,
	) {
		parent::__construct($appName, $request);
		$this->requestMapper = $requestMapper;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/map/requests')]
	public function requests(): DataResponse {
		$features = array_map(
			fn (Request $request) => $this->toFeature($request),
			$this->requestMapper->findOpenWithZip(),
		);

		return new DataResponse([
			'type' => 'FeatureCollection',
			'features' => array_values(array_filter($features)),
		]);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function toFeature(Request $request): ?array {
		$center = ZipCenters::centerOf($request->getZip());
		if ($center === null) {
			return null;
		}

		return [
			'type' => 'Feature',
			'geometry' => [
				'type' => 'Point',
				'coordinates' => [$center[1], $center[0]],
			],
			'properties' => [
				'id' => $request->getId(),
				'title' => $request->getTitle(),
				'categoryId' => $request->getCategoryId(),
				'priority' => $request->getPriority(),
				'zip' => $request->getZip(),
				'hoursBudget' => (float)$request->getHoursBudget(),
			],
		];
	}
}
