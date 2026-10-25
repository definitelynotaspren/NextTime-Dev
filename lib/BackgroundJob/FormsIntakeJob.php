<?php

declare(strict_types=1);

namespace OCA\TimeBank\BackgroundJob;

use OCA\TimeBank\Intake\FormsIntake;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Checks the chosen Nextcloud Forms for new submissions every five minutes.
 */
class FormsIntakeJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private FormsIntake $intake,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run(mixed $argument): void {
		$this->intake->sync();
	}
}
