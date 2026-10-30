<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Chapter\NationalOrganizers;
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
 * Nextcloud admins are not national organizers unless they are also in that
 * group. Grant the group explicitly.
 */
class ViewerResolver {

	public function __construct(
		private IGroupManager $groups,
		private ChapterDirectory $chapters,
		private NationalOrganizers $nationalOrganizers,
	) {
	}

	public function forUser(string $userId): Viewer {
		$national = $this->nationalOrganizers->isNationalOrganizer($userId);
		$facilitatorOf = $this->facilitatedChapters($userId);
		$facilitator = $facilitatorOf !== [] || $this->groups->isInGroup($userId, SchemaSpec::GROUP_FACILITATOR);
		$chapterMember = $facilitatorOf !== [] || $this->chapters->memberChapters($userId) !== [];

		return new Viewer($userId, $national, $facilitator, $facilitatorOf, $chapterMember);
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
