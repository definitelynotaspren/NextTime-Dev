<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Geo\Coordinates;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Read-only GeoJSON feeds for map clients such as uMap.
 *
 * Feeds are for logged-in members. Points are coarsened to an approximate
 * area and only non-contact request fields are included.
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
	#[ApiRoute(verb: 'GET', url: '/api/map/requests')]
	public function requests(): DataResponse {
		$features = array_map(
			fn (Request $request) => $this->toFeature($request),
			$this->requestMapper->findOpenWithCoordinates(),
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
		$lat = $request->getLatitude();
		$lng = $request->getLongitude();
		if ($lat === null || $lng === null) {
			return null;
		}

		return [
			'type' => 'Feature',
			'geometry' => [
				'type' => 'Point',
				'coordinates' => Coordinates::toPublic($lat, $lng),
			],
			'properties' => [
				'id' => $request->getId(),
				'title' => $request->getTitle(),
				'categoryId' => $request->getCategoryId(),
				'priority' => $request->getPriority(),
				'hoursBudget' => (float)$request->getHoursBudget(),
			],
		];
	}
}
