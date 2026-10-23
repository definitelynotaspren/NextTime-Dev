<?php

declare(strict_types=1);

namespace OCA\TimeBank\Notification;

use OCA\TimeBank\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the "a claim is waiting" notification. It carries who, hours and category
 * only. The description of the work can hold personal details and never goes in.
 */
class Notifier implements INotifier {

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('NextTime');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		if ($notification->getObjectType() !== 'claim') {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		$who = (string)($p['user'] ?? '');
		$hours = (string)($p['hours'] ?? '');
		$category = (string)($p['category'] ?? '');

		$subject = match ($notification->getSubject()) {
			'claim_pending' => $l->t('%1$s claimed %2$s hours (%3$s)', [$who, $hours, $category]),
			'claim_reminder' => $l->t('Still waiting: %1$s claimed %2$s hours (%3$s)', [$who, $hours, $category]),
			'claim_escalated' => $l->t('Waiting for over a week: %1$s claimed %2$s hours (%3$s)', [$who, $hours, $category]),
			default => throw new UnknownNotificationException(),
		};

		$notification->setParsedSubject($subject);
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute('timebank.page.index') . '#/admin/approvals');

		return $notification;
	}
}
