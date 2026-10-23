<?php

declare(strict_types=1);

namespace OCA\TimeBank\Notification;

use OCA\TimeBank\AppInfo\Application;
use OCA\TimeBank\Db\CategoryMapper;
use OCA\TimeBank\Db\Earning;
use OCA\TimeBank\Service\ApproverPolicy;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Notification\IManager;

/**
 * Tells the people who may decide on a claim that it is waiting, and clears the
 * notification for everyone once it has been decided. The claimant is never told about
 * their own claim.
 */
class ClaimNotifier {

	public function __construct(
		private IManager $notifications,
		private ApproverPolicy $policy,
		private CategoryMapper $categories,
	) {
	}

	/** First notice, when the claim is submitted. */
	public function submitted(Earning $claim): void {
		$this->send($claim, 'claim_pending', $this->policy->recipients($claim->getUserId()));
	}

	/** After 3 days: the same people again. */
	public function reminder(Earning $claim): void {
		$this->send($claim, 'claim_reminder', $this->policy->recipients($claim->getUserId()));
	}

	/** After 7 days: the admins as well. */
	public function escalate(Earning $claim): void {
		$this->send($claim, 'claim_escalated', $this->policy->admins($claim->getUserId()));
	}

	/** Approved, rejected or sent to a vote: nothing is waiting any more. */
	public function decided(Earning $claim): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setObject('claim', (string)$claim->getId());
		$this->notifications->markProcessed($notification);
	}

	/**
	 * @param list<string> $userIds
	 */
	private function send(Earning $claim, string $subject, array $userIds): void {
		try {
			$category = $this->categories->find($claim->getCategoryId())->getName();
		} catch (DoesNotExistException) {
			$category = '';
		}

		foreach ($userIds as $userId) {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('claim', (string)$claim->getId())
				->setSubject($subject, [
					'user' => $claim->getUserId(),
					'hours' => (string)(float)$claim->getActualHoursEarned(),
					'category' => $category,
				]);
			$this->notifications->notify($notification);
		}
	}
}
