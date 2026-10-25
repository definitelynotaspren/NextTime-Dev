<?php

declare(strict_types=1);

/*
 * OCP\Files\IRootFolder extends this private server interface, which nextcloud/ocp does not ship.
 * Declared here for psalm only; Nextcloud provides the real one at runtime.
 */

namespace OC\Hooks;

interface Emitter {
	/**
	 * @param string $scope
	 * @param string $method
	 * @param callable $callback
	 * @return void
	 */
	public function listen($scope, $method, callable $callback);

	/**
	 * @param string $scope optional
	 * @param string $method optional
	 * @param callable $callback optional
	 * @return void
	 */
	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
