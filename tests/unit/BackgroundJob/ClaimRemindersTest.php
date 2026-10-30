<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\BackgroundJob;

use OCA\TimeBank\BackgroundJob\ClaimReminders;
use OCA\TimeBank\Db\Earning;
use OCA\TimeBank\Db\EarningMapper;
use OCA\TimeBank\Notification\ClaimNotifier;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * A waiting claim is nudged once at 3 days and the admins are told once at 7.
 */
final class ClaimRemindersTest extends TestCase {

	private function claim(int $stage): Earning {
		$claim = new Earning();
		$claim->setId(1);
		$claim->setUserId('bob');
		$claim->setNotifiedStage($stage);
		return $claim;
	}

	private function runJob(EarningMapper $mapper, ClaimNotifier $notifier): void {
		$job = new ClaimReminders($this->createMock(ITimeFactory::class), $mapper, $notifier);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testOldClaimsAreEscalatedOnceAndRecentOnesGetAReminder(): void {
		$old = $this->claim(0);
		$waiting = $this->claim(0);
		$mapper = $this->createMock(EarningMapper::class);
		$mapper->method('findPendingOlderThan')->willReturnCallback(
			fn (\DateTime $before, int $stage) => $stage === 2 ? [$old] : ($stage === 1 ? [$waiting] : []),
		);
		$mapper->expects($this->exactly(2))->method('update');
		$notifier = $this->createMock(ClaimNotifier::class);
		$notifier->expects($this->once())->method('escalate')->with($old);
		$notifier->expects($this->once())->method('reminder')->with($waiting);

		$this->runJob($mapper, $notifier);

		$this->assertSame(2, $old->getNotifiedStage());
		$this->assertSame(1, $waiting->getNotifiedStage());
	}

	public function testNothingWaitingMeansNothingSent(): void {
		$mapper = $this->createMock(EarningMapper::class);
		$mapper->method('findPendingOlderThan')->willReturn([]);
		$notifier = $this->createMock(ClaimNotifier::class);
		$notifier->expects($this->never())->method('reminder');
		$notifier->expects($this->never())->method('escalate');

		$this->runJob($mapper, $notifier);
	}
}
