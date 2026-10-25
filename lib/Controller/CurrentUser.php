<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\NotAuthorizedException;

/**
 * The logged-in user's id. No route is a #[PublicPage], so Nextcloud refuses anonymous requests before
 * an action runs and the id is always set there; the check makes that explicit instead of assumed.
 */
trait CurrentUser {

	private function uid(): string {
		if ($this->userId === null) {
			throw new NotAuthorizedException('Login required');
		}
		return $this->userId;
	}
}
