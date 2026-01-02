<?php

declare(strict_types=1);

// API routes are defined using #[ApiRoute] attributes in the controllers
// that extend OCSController. Only page routes need to be defined here.
return [
	'routes' => [
		// Page routes
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
	],
];
