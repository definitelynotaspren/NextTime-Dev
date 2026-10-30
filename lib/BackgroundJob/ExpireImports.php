<?php

declare(strict_types=1);

namespace OCA\TimeBank\BackgroundJob;

use OCA\TimeBank\Import\ImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Expires staged imports that no second admin decided within 14 days and deletes the rows they held.
 */
class ExpireImports extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private ImportService $imports,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run(mixed $argument): void {
		$expired = $this->imports->expireStale();
		if ($expired > 0) {
			$this->logger->info('NextTime expired ' . $expired . ' staged import(s) that no second admin decided');
		}
	}
}
