<?php

declare(strict_types=1);

namespace OCA\TimeBank\Settings;

use OCA\TimeBank\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class Admin implements ISettings {

	private IConfig $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	public function getForm(): TemplateResponse {
		$parameters = [
			'require_admin_approval' => $this->config->getAppValue(Application::APP_ID, 'require_admin_approval', 'yes'),
			'enable_voting' => $this->config->getAppValue(Application::APP_ID, 'enable_voting', 'yes'),
			'required_votes' => $this->config->getAppValue(Application::APP_ID, 'required_votes', '3'),
			'allow_negative_balance' => $this->config->getAppValue(Application::APP_ID, 'allow_negative_balance', 'no'),
			'max_negative_balance' => $this->config->getAppValue(Application::APP_ID, 'max_negative_balance', '0'),
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
