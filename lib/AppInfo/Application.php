<?php

declare(strict_types=1);

namespace OCA\TimeBank\AppInfo;

use OCA\TimeBank\Notification\Notifier;
use OCA\TimeBank\Service\SystemEventRecorder;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public const string APP_ID = 'timebank';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
		// Never lets a failure to write a log line break a page.
		$context->injectFn(function (SystemEventRecorder $recorder, LoggerInterface $logger): void {
			try {
				$recorder->check();
			} catch (\Throwable $e) {
				$logger->warning('NextTime could not check its system events', ['exception' => $e]);
			}
		});
	}
}
