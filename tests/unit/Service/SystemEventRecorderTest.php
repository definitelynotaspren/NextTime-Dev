<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Service\GovernanceLog;
use OCA\TimeBank\Service\SystemEventRecorder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The public log notes, as "system", when NextTime is first recorded, is updated, or runs on a
 * different kind of database; and writes nothing when nothing changed.
 */
final class SystemEventRecorderTest extends TestCase {

	/** @var array<string, string> the app's settings, as the fake IAppConfig holds them */
	private array $settings = [];
	private string $database = 'sqlite';
	private bool $logFails = false;
	/** @var list<array{0: string, 1: string, 2: ?string, 3: array<string, mixed>}> */
	private array $logged = [];
	private SystemEventRecorder $recorder;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = ['installed_version' => '0.10.0'];
		$this->database = 'sqlite';
		$this->logFails = false;
		$this->logged = [];

		/** @var IAppConfig&MockObject $config */
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->settings[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->settings[$key] = $value;
			return true;
		});

		/** @var IDBConnection&MockObject $db */
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturnCallback(fn (): string => $this->database);

		/** @var GovernanceLog&MockObject $log */
		$log = $this->createMock(GovernanceLog::class);
		$log->method('record')->willReturnCallback(function (string $actor, string $action, ?string $subject = null, array $details = []): void {
			if ($this->logFails) {
				throw new \RuntimeException('no such table');
			}
			$this->logged[] = [$actor, $action, $subject, $details];
		});

		$this->recorder = new SystemEventRecorder($config, $db, $log, $this->createMock(LoggerInterface::class));
	}

	public function testNothingIsRecordedBeforeTheAppIsInstalled(): void {
		unset($this->settings['installed_version']);

		$this->recorder->check();

		$this->assertSame([], $this->logged);
		$this->assertArrayNotHasKey('logged_version', $this->settings);
	}

	public function testTheFirstCheckRecordsTheVersionAndTheKindOfDatabase(): void {
		$this->recorder->check();

		$this->assertSame([['system', 'system.recorded', null, ['appVersion' => '0.10.0', 'database' => 'sqlite']]], $this->logged);
		$this->assertSame('0.10.0', $this->settings['logged_version']);
		$this->assertSame('sqlite', $this->settings['logged_database']);
	}

	public function testNothingChangedWritesNothing(): void {
		$this->recorder->check();
		$this->recorder->check();
		$this->recorder->check();

		$this->assertCount(1, $this->logged);
	}

	public function testAnUpdateIsRecordedWithTheOldAndNewVersion(): void {
		$this->recorder->check();
		$this->settings['installed_version'] = '0.11.0';

		$this->recorder->check();

		$this->assertCount(2, $this->logged);
		$this->assertSame('system.app_updated', $this->logged[1][1]);
		$this->assertSame(['from' => '0.10.0', 'to' => '0.11.0'], $this->logged[1][3]['appVersion']);
		$this->assertSame('0.11.0', $this->settings['logged_version']);
	}

	public function testAChangeOfDatabaseKindIsRecorded(): void {
		$this->recorder->check();
		$this->database = 'postgres';

		$this->recorder->check();

		$this->assertCount(2, $this->logged);
		$this->assertSame('system.database_changed', $this->logged[1][1]);
		$this->assertSame(['database' => ['from' => 'sqlite', 'to' => 'postgres']], $this->logged[1][3]);
		$this->assertSame('postgres', $this->settings['logged_database']);
	}

	public function testBothChangingAtOnceIsTwoLines(): void {
		$this->recorder->check();
		$this->settings['installed_version'] = '0.11.0';
		$this->database = 'postgres';

		$this->recorder->check();

		$this->assertSame(['system.recorded', 'system.app_updated', 'system.database_changed'], array_column($this->logged, 1));
	}

	public function testOnlyTheVersionAndTheKindOfDatabaseAreEverWritten(): void {
		$this->recorder->check();
		$this->database = 'postgres';
		$this->settings['installed_version'] = '0.11.0';
		$this->recorder->check();

		$this->assertNotEmpty($this->logged);
		foreach ($this->logged as [, , , $details]) {
			$this->assertSame([], array_diff(array_keys($details), ['appVersion', 'database']));
		}
	}

	public function testAFailedWriteIsRetriedNotMarkedAsDone(): void {
		$this->logFails = true;

		$this->recorder->check();

		$this->assertSame([], $this->logged);
		$this->assertArrayNotHasKey('logged_version', $this->settings);

		$this->logFails = false;
		$this->recorder->check();

		$this->assertCount(1, $this->logged);
		$this->assertSame('0.10.0', $this->settings['logged_version']);
	}
}
