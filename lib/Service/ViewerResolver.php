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
 * Facilitating a chapter takes two groups, `chapter-<slug>` and `chapter-<slug>-facilitator`.
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

		$chapters = $this->facilitatedChapters($userId);

		return new Viewer($userId, $organizer, $facilitator || $chapters !== [], $chapters);
	}

	/**
	 * Chapters this person belongs to. Belonging gives no powers.
	 *
	 * @return list<string>
	 */
	public function memberChapters(string $userId): array {
		$chapters = [];
		foreach (SchemaSpec::chapters() as $slug => $chapter) {
			if ($this->groups->isInGroup($userId, $chapter['group'])) {
				$chapters[] = $slug;
			}
		}

		return $chapters;
	}

	/**
	 * Chapters this person facilitates: they need the chapter's member group and its
	 * facilitator group. Either one alone does nothing.
	 *
	 * @return list<string>
	 */
	public function facilitatedChapters(string $userId): array {
		$chapters = [];
		foreach (SchemaSpec::chapters() as $slug => $chapter) {
			if ($this->groups->isInGroup($userId, $chapter['group'])
				&& $this->groups->isInGroup($userId, $chapter['facilitator_group'])) {
				$chapters[] = $slug;
			}
		}

		return $chapters;
	}
}
