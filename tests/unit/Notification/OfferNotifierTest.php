<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Notification;

use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\Volunteer;
use OCA\TimeBank\Notification\Notifier;
use OCA\TimeBank\Notification\OfferNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class OfferNotifierTest extends TestCase {

	private function offer(): Volunteer {
		$offer = new Volunteer();
		$offer->setId(9);
		$offer->setRequestId(3);
		$offer->setVolunteerId('bob');
		$offer->setProposedHours('2');
		$offer->setMessage('I can come Saturday, my number is 555-0100');
		return $offer;
	}

	private function request(): Request {
		$request = new Request();
		$request->setId(3);
		$request->setRequesterId('alice');
		$request->setTitle('Help moving a couch');
		return $request;
	}

	/**
	 * @param list<array{string, string, array<string, string>}> $sent
	 */
	private function manager(array &$sent): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function () use (&$sent) {
			$n = $this->createMock(INotification::class);
			$user = '';
			foreach (['setApp', 'setDateTime', 'setObject'] as $m) {
				$n->method($m)->willReturnSelf();
			}
			$n->method('setUser')->willReturnCallback(function (string $u) use (&$user, $n) {
				$user = $u;
				return $n;
			});
			$n->method('setSubject')->willReturnCallback(function (string $subject, array $params) use (&$sent, &$user, $n) {
				$sent[] = [$user, $subject, $params];
				return $n;
			});
			return $n;
		});

		return $manager;
	}

	public function testTheRequesterIsToldOfAnOfferWithoutTheMessage(): void {
		$sent = [];
		(new OfferNotifier($this->manager($sent), $this->createMock(LoggerInterface::class)))->offered($this->offer(), $this->request());

		$this->assertCount(1, $sent);
		$this->assertSame('alice', $sent[0][0]);
		$this->assertSame('offer_made', $sent[0][1]);
		$this->assertSame('bob', $sent[0][2]['user']);
		$this->assertStringNotContainsString('555', (string)json_encode($sent));
	}

	public function testTheVolunteerIsToldWhenAccepted(): void {
		$sent = [];
		$offer = $this->offer();
		$offer->setAgreedHours('3');
		(new OfferNotifier($this->manager($sent), $this->createMock(LoggerInterface::class)))->accepted($offer, $this->request());

		$this->assertSame('bob', $sent[0][0]);
		$this->assertSame('offer_accepted', $sent[0][1]);
		$this->assertSame('3', $sent[0][2]['hours'], 'the agreed hours, not the offered ones');
	}

	public function testABrokenBackendNeverBlocksAnOffer(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willThrowException(new \RuntimeException('backend down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(3))->method('warning');

		$notifier = new OfferNotifier($manager, $logger);
		$notifier->offered($this->offer(), $this->request());
		$notifier->accepted($this->offer(), $this->request());
		$notifier->answered($this->offer());
		$this->addToAssertionCount(1);
	}

	public function testTheNotifierWritesBothKinds(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $p = []) => vsprintf($text, $p));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/index.php/apps/timebank/');
		$notifier = new Notifier($factory, $urls);

		foreach (['offer_made' => 'bob offered 2 hours on "Help moving a couch"', 'offer_accepted' => 'Your offer on "Help moving a couch" was accepted for 2 hours'] as $subject => $text) {
			$n = $this->createMock(INotification::class);
			$n->method('getApp')->willReturn('timebank');
			$n->method('getObjectType')->willReturn('offer');
			$n->method('getSubject')->willReturn($subject);
			$n->method('getSubjectParameters')->willReturn(['user' => 'bob', 'hours' => '2', 'title' => 'Help moving a couch', 'request' => '3']);
			$n->expects($this->once())->method('setParsedSubject')->with($text);
			$n->expects($this->once())->method('setLink')->with('https://cloud.example/index.php/apps/timebank/#/request/3');
			$notifier->prepare($n, 'en');
		}
	}
}
