<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Notification;

use OCA\TimeBank\Db\Category;
use OCA\TimeBank\Db\CategoryMapper;
use OCA\TimeBank\Db\Earning;
use OCA\TimeBank\Notification\ClaimNotifier;
use OCA\TimeBank\Service\ApproverPolicy;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Notifications are a courtesy: a broken backend must never stop a claim being submitted or decided.
 */
final class ClaimNotifierTest extends TestCase {

	private function claim(): Earning {
		$claim = new Earning();
		$claim->setId(4);
		$claim->setUserId('bob');
		$claim->setCategoryId(1);
		$claim->setActualHoursEarned('2.00');
		return $claim;
	}

	public function testABrokenBackendDoesNotBlockSubmittingOrDeciding(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willThrowException(new \RuntimeException('backend down'));
		$policy = $this->createMock(ApproverPolicy::class);
		$policy->method('recipients')->willReturn(['carol']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');

		$notifier = new ClaimNotifier($manager, $policy, $this->createMock(CategoryMapper::class), $logger);
		$notifier->submitted($this->claim());
		$notifier->decided($this->claim());
		$this->addToAssertionCount(1);
	}

	public function testEveryRecipientGetsOneNoticeWithoutTheDescription(): void {
		$sent = [];
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function () use (&$sent) {
			$n = $this->createMock(INotification::class);
			foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $m) {
				$n->method($m)->willReturnSelf();
			}
			$n->method('setSubject')->willReturnCallback(function (string $subject, array $params) use (&$sent, $n) {
				$sent[] = [$subject, $params];
				return $n;
			});
			return $n;
		});
		$policy = $this->createMock(ApproverPolicy::class);
		$policy->method('recipients')->willReturn(['carol', 'fay']);
		$claim = $this->claim();
		$claim->setDescription('Drove Mrs. Lee to chemo at 12 Elm Street');

		$category = new Category();
		$category->setName('Gardening');
		$categories = $this->createMock(CategoryMapper::class);
		$categories->method('find')->willReturn($category);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		(new ClaimNotifier($manager, $policy, $categories, $logger))->submitted($claim);

		$this->assertCount(2, $sent);
		$this->assertSame('claim_pending', $sent[0][0]);
		$this->assertStringNotContainsString('Elm Street', json_encode($sent));
		$this->assertSame(['user', 'hours', 'category'], array_keys($sent[0][1]));
	}
}
