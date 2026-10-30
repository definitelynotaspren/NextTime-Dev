<?php

declare(strict_types=1);

namespace OCA\TimeBank\Import;

use OCA\TimeBank\Service\InvalidStateException;
use OCP\IConfig;

/**
 * Opens the source an admin described. Separate from the import service so that a test can hand the
 * service a source without a database behind it.
 */
class SourceConnector {

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * @param array<string, mixed> $params
	 * @throws InvalidStateException when the details are wrong or the source cannot be reached
	 */
	public function open(string $kind, array $params): Source {
		return DatabaseSource::connect($kind, $params, $this->config->getSystemValueString('datadirectory', ''));
	}
}
