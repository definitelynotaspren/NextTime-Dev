<?php

declare(strict_types=1);

namespace OCA\TimeBank\Notification;

use OCA\TimeBank\AppInfo\Application;
use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\Volunteer;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Tells a requester that someone offered to help, and the volunteer that their offer was accepted.
 * Notices carry the request's title (already public on the board), the volunteer and the hours,
 * never the volunteer's message. A notice waiting for the requester is cleared once the offer is
 * answered or withdrawn.
 *
 * A notification is a courtesy: a broken backend never blocks offering, accepting or declining.
 */
class OfferNotifier {

	public function __construct(
		private IManager $notifications,
		private LoggerInterface $logger,
	) {
	}

	/** To the requester, when an offer comes in. */
	public function offered(Volunteer $offer, Request $request): void {
		$this->send($offer, $request, 'offer_made', $request->getRequesterId());
	}

	/** To the volunteer, when the requester accepts. */
	public function accepted(Volunteer $offer, Request $request): void {
		$this->send($offer, $request, 'offer_accepted', $offer->getVolunteerId());
	}

	/** Accepted, declined or withdrawn: the requester no longer has anything to answer. */
	public function answered(Volunteer $offer): void {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setObject('offer', (string)$offer->getId());
			$this->notifications->markProcessed($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('NextTime could not clear an offer notification', ['exception' => $e]);
		}
	}

	private function send(Volunteer $offer, Request $request, string $subject, string $userId): void {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('offer', (string)$offer->getId())
				->setSubject($subject, [
					'user' => $offer->getVolunteerId(),
					'hours' => (string)$offer->effectiveHours(),
					'title' => $request->getTitle(),
					'request' => (string)$request->getId(),
				]);
			$this->notifications->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('NextTime could not send an offer notification', ['exception' => $e]);
		}
	}
}
