<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\TimeBank\AppInfo\Application::APP_ID, OCA\TimeBank\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\TimeBank\AppInfo\Application::APP_ID, OCA\TimeBank\AppInfo\Application::APP_ID . '-main');

?>

<div id="timebank"></div>
