<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Schema\SchemaSpec;
use OCA\TimeBank\Schema\Viewer;
use OCP\IGroupManager;

/**
 * Builds a Viewer from Nextcloud group membership. Roles are read here, on the
 * server, from the current user; nothing about the caller's role comes from
 * request parameters.
 *
 * Facilitating a chapter means being in that chapter's facilitator group (see ChapterDirectory).
 *
 * Nextcloud admins are not organizers unless they are also in the organizer
 * group. Grant the group explicitly.
 */
class ViewerResolver {

	public function __construct(
		private IGroupManager $groups,
		private ChapterDirectory $chapters,
	) {
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
		return $this->chapters->memberChapters($userId);
	}

	/**
	 * Chapters this person facilitates: they are in the chapter's facilitator group.
	 *
	 * @return list<string>
	 */
	public function facilitatedChapters(string $userId): array {
		return $this->chapters->facilitatedChapters($userId);
	}
}
