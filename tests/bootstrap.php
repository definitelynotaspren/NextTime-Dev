<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap for the TimeBank app.
 *
 * The tests need a Nextcloud server checkout (for OCP/OC classes). It is found
 * via NEXTCLOUD_ROOT, falling back to the classic layout where this app lives
 * at <server>/apps/timebank. scripts/dev-native.sh creates ../nc-server, so:
 *
 *   NEXTCLOUD_ROOT=../nc-server composer test:unit
 */

$serverRoot = getenv('NEXTCLOUD_ROOT') ?: __DIR__ . '/../../..';
$serverRoot = realpath($serverRoot) ?: $serverRoot;

if (!is_file($serverRoot . '/tests/bootstrap.php')) {
	fwrite(STDERR, "Nextcloud server not found at '$serverRoot'.\n"
		. "Run scripts/dev-native.sh setup, then: NEXTCLOUD_ROOT=../nc-server composer test:unit\n");
	exit(1);
}

require_once $serverRoot . '/tests/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

\OC_App::loadApp(\OCA\TimeBank\AppInfo\Application::APP_ID);
OC_Hook::clear();
