<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;

class PageController extends Controller {

	/**
	 * Host that serves the map style, sprites, fonts and tiles (OpenFreeMap).
	 * Keep in sync with MAP_STYLE_URL in src/composables/useMap.ts. Nextcloud's
	 * default policy only allows connections to itself, so without this the
	 * map style fetch is refused and no map or markers ever appear.
	 */
	private const MAP_HOST = 'https://tiles.openfreemap.org';

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		$response = new TemplateResponse(
			Application::APP_ID,
			'index',
		);

		$csp = new ContentSecurityPolicy();
		$csp->addAllowedConnectDomain(self::MAP_HOST);
		$csp->addAllowedImageDomain(self::MAP_HOST);
		// MapLibre runs tile decoding in a blob: web worker.
		$csp->addAllowedWorkerSrcDomain('blob:');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}
}
