<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Notes in the public governance log, as the actor "system", when NextTime's running setup changes:
 * the first time it is seen, when its version changes (an update that has finished its migrations),
 * and when the kind of database under it changes (for example SQLite moved to PostgreSQL).
 *
 * Nextcloud, not NextTime, owns the database connection: it reads it from config.php on every
 * request. So there is no connection for NextTime to "recreate"; what can be shown to members is
 * the moments that setup changed. Only the version and the kind of database are written, never the
 * host, the database name or any credential, because every member can read the log.
 *
 * It is checked when the app loads and writes only when something differs from what was last
 * recorded, so a normal request costs two settings reads. Two requests that both see the same change
 * at the same moment can each write it; the log is only ever added to, so that shows as a repeated line.
 */
class SystemEventRecorder {

	public const string ACTOR = 'system';
	private const string KEY_VERSION = 'logged_version';
	private const string KEY_DATABASE = 'logged_database';

	public function __construct(
		private IAppConfig $config,
		private IDBConnection $db,
		private GovernanceLog $log,
		private LoggerInterface $logger,
	) {
	}

	public function check(): void {
		// Set by Nextcloud once the app's migrations have finished, so an update is recorded
		// after it worked, not while it is half done.
		$version = $this->config->getValueString(Application::APP_ID, 'installed_version');
		if ($version === '') {
			return;
		}
		$database = $this->db->getDatabaseProvider();
		$lastVersion = $this->config->getValueString(Application::APP_ID, self::KEY_VERSION);
		$lastDatabase = $this->config->getValueString(Application::APP_ID, self::KEY_DATABASE);
		if ($version === $lastVersion && $database === $lastDatabase) {
			return;
		}

		try {
			if ($lastVersion === '' && $lastDatabase === '') {
				$this->log->record(self::ACTOR, 'system.recorded', null, ['appVersion' => $version, 'database' => $database]);
			} else {
				if ($lastVersion !== $version) {
					$this->log->record(self::ACTOR, 'system.app_updated', null, [
						'appVersion' => ['from' => $lastVersion === '' ? null : $lastVersion, 'to' => $version],
						'database' => $database,
					]);
				}
				if ($lastDatabase !== $database) {
					$this->log->record(self::ACTOR, 'system.database_changed', null, [
						'database' => ['from' => $lastDatabase === '' ? null : $lastDatabase, 'to' => $database],
					]);
				}
			}
		} catch (\Throwable $e) {
			// The log's table may not exist yet (migrations still running). Nothing is marked as
			// recorded, so the next request tries again.
			$this->logger->debug('NextTime could not record a system event yet', ['exception' => $e]);
			return;
		}

		$this->config->setValueString(Application::APP_ID, self::KEY_VERSION, $version);
		$this->config->setValueString(Application::APP_ID, self::KEY_DATABASE, $database);
	}
}
