<?php

declare(strict_types=1);

namespace OCA\TimeBank\Settings;

use OCA\TimeBank\AppInfo\Application;
use OCA\TimeBank\Service\AdjustmentService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\ISettings;

class Admin implements ISettings {

	private IAppConfig $config;

	public function __construct(IAppConfig $config) {
		$this->config = $config;
	}

	public function getForm(): TemplateResponse {
		$parameters = [
			'adjustment_threshold' => (float)$this->config->getValueString(
				Application::APP_ID,
				'adjustment_threshold',
				(string)AdjustmentService::DEFAULT_THRESHOLD,
			),
		];

		return new TemplateResponse(Application::APP_ID, 'settings/admin', $parameters);
	}

	public function getSection(): string {
		return 'timebank';
	}

	public function getPriority(): int {
		return 50;
	}
}
