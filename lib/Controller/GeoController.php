<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Geo\ZipCenters;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;

/**
 * The bundled ZIP-center table, so the posting form can turn a dropped pin into a ZIP in the
 * browser. The pin itself is never sent here: the browser downloads the whole (public, Census)
 * table and looks the pin up locally.
 */
class GeoController extends Controller {

	public function __construct(string $appName, IRequest $request) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/geo/zip-centers')]
	public function zipCenters(): DataDisplayResponse {
		$table = @file_get_contents(ZipCenters::dataFile());
		if ($table === false) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}

		$response = new DataDisplayResponse($table, Http::STATUS_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
		// Changes only with an app update; let the browser keep it for a week.
		$response->cacheFor(7 * 24 * 3600);

		return $response;
	}
}
