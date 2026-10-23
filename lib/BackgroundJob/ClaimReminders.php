<?php

declare(strict_types=1);

namespace OCA\TimeBank\BackgroundJob;

use OCA\TimeBank\Db\EarningMapper;
use OCA\TimeBank\Notification\ClaimNotifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Nudges the people who can decide on a claim that has waited: a reminder after 3 days,
 * and the admins after 7. Each claim is nudged once per stage.
 */
class ClaimReminders extends TimedJob {

	public const REMIND_AFTER = '-3 days';
	public const ESCALATE_AFTER = '-7 days';

	public function __construct(
		ITimeFactory $time,
		private EarningMapper $earnings,
		private ClaimNotifier $notifier,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void {
		foreach ($this->earnings->findPendingOlderThan(new \DateTime(self::ESCALATE_AFTER), 2) as $claim) {
			$this->notifier->escalate($claim);
			$claim->setNotifiedStage(2);
			$this->earnings->update($claim);
		}
		foreach ($this->earnings->findPendingOlderThan(new \DateTime(self::REMIND_AFTER), 1) as $claim) {
			$this->notifier->reminder($claim);
			$claim->setNotifiedStage(1);
			$this->earnings->update($claim);
		}
	}
}
