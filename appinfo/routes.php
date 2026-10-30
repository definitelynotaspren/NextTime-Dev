<?php

declare(strict_types=1);

// All routes are registered via PHP 8 attributes on the controllers:
//   #[FrontpageRoute] for the page route in PageController
//   #[ApiRoute]       for all API endpoints
return ['routes' => []];
// All routes (page + API) are defined via #[FrontpageRoute] and #[ApiRoute]
// attributes on the controllers. Keeping this file empty avoids duplicate
// route-name registration on Nextcloud 29+.
return [
	'routes' => [],
];
