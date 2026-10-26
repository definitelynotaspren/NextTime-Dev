<?php

declare(strict_types=1);

namespace OCA\TimeBank\Import;

use OCA\TimeBank\Service\InvalidStateException;
use PDO;
use PDOException;

/**
 * Reads another NextTime's tables straight from its database (PostgreSQL, MySQL/MariaDB or a SQLite
 * file) with plain PDO. It only ever sends SELECT, and asks the database to refuse writes on this
 * connection as well (read-only transaction or query-only pragma), so a mistake here cannot change
 * what it reads. The connection details are used for this one connection and are never stored.
 *
 * A SQLite source must be a file inside Nextcloud's own data folder, so a path typed into the form
 * cannot be used to open some other file on the server.
 */
final class DatabaseSource implements Source {

	private const array KINDS = ['pgsql', 'mysql', 'sqlite'];
	private const int TIMEOUT_SECONDS = 5;

	public function __construct(
		private PDO $pdo,
		private string $prefix,
	) {
	}

	/**
	 * @param array<string, mixed> $params host, port, database, user, password, prefix; for SQLite, file
	 * @throws InvalidStateException with a plain message when a field is wrong or the database cannot be reached
	 */
	public static function connect(string $kind, array $params, string $dataDirectory): self {
		if (!in_array($kind, self::KINDS, true)) {
			throw new InvalidStateException('The source must be PostgreSQL, MySQL or a SQLite file');
		}
		$prefix = self::plain($params['prefix'] ?? 'oc_');
		if (preg_match('/^[A-Za-z0-9_]{0,20}$/', $prefix) !== 1) {
			throw new InvalidStateException('The table prefix may only hold letters, digits and underscores (Nextcloud\'s default is oc_)');
		}
		if (preg_match('/timebank_?$/i', $prefix) === 1) {
			throw new InvalidStateException('The table prefix is what comes before "timebank_requests" in the table names, usually oc_. It should not include "timebank".');
		}
		// exactly as typed: a password may begin or end with a space
		$password = is_scalar($params['password'] ?? null) ? (string)$params['password'] : '';

		try {
			if ($kind === 'sqlite') {
				$pdo = new PDO('sqlite:' . self::sqlitePath(self::plain($params['file'] ?? ''), $dataDirectory), null, null, self::options());
				$pdo->exec('PRAGMA query_only = ON');
			} else {
				$host = self::plain($params['host'] ?? '');
				$database = self::plain($params['database'] ?? '');
				$port = (int)self::plain($params['port'] ?? '');
				if (preg_match('/^[A-Za-z0-9._-]{1,253}$/', $host) !== 1) {
					throw new InvalidStateException('The host may only hold letters, digits, dots, dashes and underscores');
				}
				if (preg_match('/^[A-Za-z0-9_$-]{1,63}$/', $database) !== 1) {
					throw new InvalidStateException('The database name may only hold letters, digits, dashes, dollar signs and underscores');
				}
				if ($port === 0) {
					$port = $kind === 'pgsql' ? 5432 : 3306;
				}
				if ($port < 1 || $port > 65535) {
					throw new InvalidStateException('The port must be between 1 and 65535');
				}
				$user = self::plain($params['user'] ?? '');
				if ($kind === 'pgsql') {
					$pdo = new PDO("pgsql:host=$host;port=$port;dbname=$database;connect_timeout=" . self::TIMEOUT_SECONDS, $user, $password, self::options());
					$pdo->exec('SET default_transaction_read_only = on');
				} else {
					$pdo = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $user, $password, self::options() + [PDO::ATTR_TIMEOUT => self::TIMEOUT_SECONDS]);
					$pdo->exec('SET SESSION TRANSACTION READ ONLY');
				}
			}
		} catch (PDOException $e) {
			throw new InvalidStateException('Could not open the source: ' . self::withoutSecret($e->getMessage(), $password) . self::hint($e->getMessage()));
		}

		return new self($pdo, $prefix);
	}

	#[\Override]
	public function requests(): array {
		return $this->all('timebank_requests');
	}

	#[\Override]
	public function volunteers(): array {
		return $this->all('timebank_volunteers');
	}

	#[\Override]
	public function earnings(): array {
		return $this->all('timebank_earnings');
	}

	#[\Override]
	public function categoryNames(): array {
		try {
			$rows = $this->all('timebank_categories');
		} catch (InvalidStateException) {
			return [];
		}
		$names = [];
		foreach ($rows as $row) {
			if (isset($row['id'], $row['name']) && is_scalar($row['id']) && is_scalar($row['name'])) {
				$names[(string)$row['id']] = (string)$row['name'];
			}
		}

		return $names;
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws InvalidStateException when the table is not there
	 */
	private function all(string $table): array {
		$name = $this->prefix . $table;
		try {
			$statement = $this->pdo->query('SELECT * FROM ' . $name . ' ORDER BY id');
			$rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException) {
			throw new InvalidStateException("The source has no table $name. Is this a NextTime database? The table prefix is what comes before \"timebank_requests\" in the table names, usually oc_.");
		}

		/** @var list<array<string, mixed>> $rows */
		return $rows;
	}

	/**
	 * @return array<int, mixed>
	 */
	private static function options(): array {
		return [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
	}

	/**
	 * @throws InvalidStateException unless it names an existing file inside the data folder
	 */
	private static function sqlitePath(string $file, string $dataDirectory): string {
		if (preg_match('/^[A-Za-z0-9._-]{1,100}$/', $file) !== 1 || str_starts_with($file, '.')) {
			throw new InvalidStateException('Give only the name of the SQLite file, which must be inside Nextcloud\'s data folder');
		}
		$root = realpath($dataDirectory);
		$path = $root === false ? false : realpath($root . '/' . $file);
		if ($root === false || $path === false || !str_starts_with($path, $root . '/') || !is_file($path) || !is_readable($path)) {
			throw new InvalidStateException('There is no readable file with that name in Nextcloud\'s data folder');
		}

		return $path;
	}

	private static function plain(mixed $value): string {
		return is_scalar($value) ? trim((string)$value) : '';
	}

	/**
	 * A plain next step for the two mistakes people make most: a Nextcloud login in place of the database's own
	 * user, and a table prefix in place of the database name.
	 */
	private static function hint(string $message): string {
		return match (true) {
			str_contains($message, 'authentication failed'), str_contains($message, 'Access denied') => ' The user and password are the database\'s own, not a Nextcloud login (for the Docker stack, POSTGRES_USER and POSTGRES_PASSWORD in the .env file).',
			str_contains($message, 'does not exist'), str_contains($message, 'Unknown database') => ' Check the database name; the table prefix has its own field.',
			default => '',
		};
	}

	private static function withoutSecret(string $message, string $secret): string {
		if ($secret !== '') {
			$message = str_replace($secret, '***', $message);
		}

		return substr($message, 0, 300);
	}
}
