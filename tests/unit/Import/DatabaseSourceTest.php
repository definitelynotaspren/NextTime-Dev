<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Import;

use OCA\TimeBank\Import\DatabaseSource;
use OCA\TimeBank\Service\InvalidStateException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Reading another NextTime's database: what it reads, what it refuses, and that it cannot write.
 */
final class DatabaseSourceTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/timebank-import-' . bin2hex(random_bytes(4));
		mkdir($this->dir . '/data', 0777, true);
		$pdo = new PDO('sqlite:' . $this->dir . '/data/old.db');
		$pdo->exec('CREATE TABLE oc_timebank_requests (id INTEGER PRIMARY KEY, requester_id TEXT, title TEXT, category_id INTEGER)');
		$pdo->exec('CREATE TABLE oc_timebank_volunteers (id INTEGER PRIMARY KEY, request_id INTEGER, volunteer_id TEXT)');
		$pdo->exec('CREATE TABLE oc_timebank_earnings (id INTEGER PRIMARY KEY, user_id TEXT, status TEXT)');
		$pdo->exec('CREATE TABLE oc_timebank_categories (id INTEGER PRIMARY KEY, name TEXT)');
		$pdo->exec("INSERT INTO oc_timebank_requests VALUES (2, 'ben', 'Second', 1), (1, 'ana', 'First', 1)");
		$pdo->exec("INSERT INTO oc_timebank_volunteers VALUES (7, 1, 'ben')");
		$pdo->exec("INSERT INTO oc_timebank_earnings VALUES (9, 'ben', 'approved')");
		$pdo->exec("INSERT INTO oc_timebank_categories VALUES (1, 'Childcare'), (2, 'Tech Help')");
		$pdo = null;
	}

	protected function tearDown(): void {
		foreach (glob($this->dir . '/data/{,.}*', GLOB_BRACE) ?: [] as $path) {
			if (is_link($path) || is_file($path)) {
				unlink($path);
			}
		}
		@rmdir($this->dir . '/data');
		foreach (glob($this->dir . '/*') ?: [] as $path) {
			is_file($path) || is_link($path) ? unlink($path) : null;
		}
		@rmdir($this->dir);
		parent::tearDown();
	}

	private function source(string $file = 'old.db', string $prefix = 'oc_'): DatabaseSource {
		return DatabaseSource::connect('sqlite', ['file' => $file, 'prefix' => $prefix], $this->dir . '/data');
	}

	public function testItReadsEveryTableInIdOrder(): void {
		$source = $this->source();

		$this->assertSame(['First', 'Second'], array_column($source->requests(), 'title'));
		$this->assertSame('ben', $source->volunteers()[0]['volunteer_id']);
		$this->assertSame('approved', $source->earnings()[0]['status']);
		$this->assertSame(['1' => 'Childcare', '2' => 'Tech Help'], $source->categoryNames());
	}

	public function testATablePrefixOtherThanTheDefaultIsHonoured(): void {
		$pdo = new PDO('sqlite:' . $this->dir . '/data/other.db');
		$pdo->exec('CREATE TABLE nc_timebank_requests (id INTEGER PRIMARY KEY, title TEXT)');
		$pdo->exec("INSERT INTO nc_timebank_requests VALUES (1, 'Prefixed')");
		$pdo = null;

		$this->assertSame('Prefixed', $this->source('other.db', 'nc_')->requests()[0]['title']);
	}

	public function testAMissingTableSaysWhichAndHintsAtThePrefix(): void {
		$this->expectException(InvalidStateException::class);
		$this->expectExceptionMessage('oc_timebank_requests');

		$empty = new PDO('sqlite::memory:');
		(new DatabaseSource($empty, 'oc_'))->requests();
	}

	public function testAMissingCategoriesTableJustMeansNoNames(): void {
		$pdo = new PDO('sqlite::memory:');

		$this->assertSame([], (new DatabaseSource($pdo, 'oc_'))->categoryNames());
	}

	public function testTheConnectionCannotWrite(): void {
		$property = new \ReflectionProperty($this->source(), 'pdo');
		/** @var PDO $pdo */
		$pdo = $property->getValue($this->source());
		$this->expectException(PDOException::class);

		$pdo->exec("INSERT INTO oc_timebank_requests VALUES (99, 'x', 'Should not be written', 1)");
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function badConnections(): array {
		return [
			'unknown kind' => ['oracle', ['host' => 'db', 'database' => 'x']],
			'prefix with a quote' => ['pgsql', ['host' => 'db', 'database' => 'x', 'prefix' => "oc_'; DROP"]],
			'prefix too long' => ['pgsql', ['host' => 'db', 'database' => 'x', 'prefix' => str_repeat('a', 21)]],
			'host with a semicolon' => ['pgsql', ['host' => 'db;host=evil', 'database' => 'x']],
			'empty host' => ['mysql', ['host' => '', 'database' => 'x']],
			'database name with a space' => ['pgsql', ['host' => 'db', 'database' => 'a b']],
			'port zero is the default, but too large is refused' => ['pgsql', ['host' => 'db', 'database' => 'x', 'port' => '70000']],
			'sqlite with a path' => ['sqlite', ['file' => 'sub/old.db']],
			'sqlite walking up' => ['sqlite', ['file' => '../old.db']],
			'sqlite absolute path' => ['sqlite', ['file' => '/etc/passwd']],
			'sqlite hidden file' => ['sqlite', ['file' => '.hidden.db']],
			'sqlite file that is not there' => ['sqlite', ['file' => 'nope.db']],
			'sqlite no name' => ['sqlite', ['file' => '']],
		];
	}

	/**
	 * @param array<string, mixed> $params
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('badConnections')]
	public function testBadFieldsAreRefusedBeforeAnythingIsOpened(string $kind, array $params): void {
		$this->expectException(InvalidStateException::class);

		DatabaseSource::connect($kind, $params, $this->dir . '/data');
	}

	public function testASymlinkOutOfTheDataFolderIsRefused(): void {
		file_put_contents($this->dir . '/outside.db', 'x');
		symlink($this->dir . '/outside.db', $this->dir . '/data/link.db');
		$this->expectException(InvalidStateException::class);

		$this->source('link.db');
	}

	public function testAPasswordNeverAppearsInAnErrorMessage(): void {
		if (!extension_loaded('pdo_pgsql')) {
			$this->markTestSkipped('pdo_pgsql is not installed');
		}
		try {
			DatabaseSource::connect('pgsql', ['host' => '127.0.0.1', 'port' => '1', 'database' => 'x', 'user' => 'u', 'password' => 'hunter2-secret'], $this->dir . '/data');
			$this->fail('connecting to a closed port should fail');
		} catch (InvalidStateException $e) {
			$this->assertStringStartsWith('Could not open the source', $e->getMessage());
			$this->assertStringNotContainsString('hunter2-secret', $e->getMessage());
		}
	}
}
