<?php

declare(strict_types=1);

namespace OCA\TimeBank\Notification;

use OCA\TimeBank\AppInfo\Application;
use OCP\IL10N;
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
			throw $this->unknown();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();

		if ($notification->getObjectType() === 'offer') {
			return $this->prepareOffer($notification, $l, $p);
		}
		if ($notification->getObjectType() !== 'claim') {
			throw $this->unknown();
		}

		$who = (string)($p['user'] ?? '');
		$hours = (string)($p['hours'] ?? '');
		$category = (string)($p['category'] ?? '');

		$subject = match ($notification->getSubject()) {
			'claim_pending' => $l->t('%1$s claimed %2$s hours (%3$s)', [$who, $hours, $category]),
			'claim_reminder' => $l->t('Still waiting: %1$s claimed %2$s hours (%3$s)', [$who, $hours, $category]),
			'claim_escalated' => $l->t('Waiting for over a week: %1$s claimed %2$s hours (%3$s)', [$who, $hours, $category]),
			default => throw $this->unknown(),
		};

		$notification->setParsedSubject($subject);
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute('timebank.page.index') . '#/admin/approvals');

		return $notification;
	}

	/**
	 * @param array<array-key, mixed> $p
	 */
	private function prepareOffer(INotification $notification, IL10N $l, array $p): INotification {
		$who = (string)($p['user'] ?? '');
		$hours = (string)($p['hours'] ?? '');
		$title = (string)($p['title'] ?? '');

		$subject = match ($notification->getSubject()) {
			'offer_made' => $l->t('%1$s offered %2$s hours on "%3$s"', [$who, $hours, $title]),
			'offer_accepted' => $l->t('Your offer on "%1$s" was accepted for %2$s hours', [$title, $hours]),
			default => throw $this->unknown(),
		};

		$notification->setParsedSubject($subject);
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute('timebank.page.index') . '#/request/' . (int)($p['request'] ?? 0));

		return $notification;
	}

	/**
	 * UnknownNotificationException only exists from Nextcloud 30. On 29, `new` on it is a fatal
	 * Error that Nextcloud does not catch, which would break every user's notification list.
	 *
	 * @psalm-suppress MixedInferredReturnType, MixedReturnStatement the class is absent on 29
	 */
	private function unknown(): \InvalidArgumentException {
		if (class_exists(UnknownNotificationException::class)) {
			return new UnknownNotificationException();
		}
		return new \InvalidArgumentException('Not a NextTime notification');
	}
}
