<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Notification;

use OCA\TimeBank\Notification\Notifier;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud asks every notifier about every notification. Ones that are not ours must be refused
 * with an \InvalidArgumentException (or a subclass), the only thing every supported version catches.
 */
final class NotifierTest extends TestCase {

	private function notifier(): Notifier {
		return new Notifier($this->createMock(IFactory::class), $this->createMock(IURLGenerator::class));
	}

	public function testAnotherAppsNotificationIsRefusedAsUnknown(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('files_sharing');

		$this->expectException(\InvalidArgumentException::class);
		$this->notifier()->prepare($notification, 'en');
	}

	public function testOurNotificationOfAnotherKindIsRefusedAsUnknown(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('timebank');
		$notification->method('getObjectType')->willReturn('comment');

		$this->expectException(\InvalidArgumentException::class);
		$this->notifier()->prepare($notification, 'en');
	}
}
