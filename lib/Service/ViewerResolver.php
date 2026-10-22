<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Schema\SchemaSpec;
use OCA\TimeBank\Schema\Viewer;
use OCP\IGroupManager;

/**
 * Builds a Viewer from Nextcloud group membership. Roles are read here, on the
 * server, from the current user; nothing about the caller's role comes from
 * request parameters.
 *
 * Nextcloud admins are not organizers unless they are also in the organizer
 * group. Grant the group explicitly.
 */
class ViewerResolver {

	private IGroupManager $groups;

	public function __construct(IGroupManager $groups) {
		$this->groups = $groups;
	}

	public function forUser(string $userId): Viewer {
		$organizer = $this->groups->isInGroup($userId, SchemaSpec::GROUP_ORGANIZER);
		$facilitator = $this->groups->isInGroup($userId, SchemaSpec::GROUP_FACILITATOR);

		$chapters = [];
		foreach (SchemaSpec::chapters() as $slug => $chapter) {
			if ($this->groups->isInGroup($userId, $chapter['group'])) {
				$chapters[] = $slug;
			}
		}

		return new Viewer($userId, $organizer, $facilitator || $chapters !== [], $chapters);
	}
}
