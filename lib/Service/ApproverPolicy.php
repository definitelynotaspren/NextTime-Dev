<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Chapter\NationalOrganizers;
use OCP\IGroupManager;

/**
 * Who may decide on something about a member (a claim, a large balance adjustment).
 *
 * Person P may decide about member U when P is not U and one of these holds:
 *  1. P is a Nextcloud admin, so the community can never be locked out.
 *  2. P is in the facilitator group of a chapter U belongs to. The facilitator group is enough on its
 *     own; P need not also be in the chapter's member group. Chapters are found by ChapterDirectory.
 *  3. P is a national organizer (see NationalOrganizers). They act for every member, so members
 *     whose state has no facilitator, or who have no state, are decided by them.
 */
class ApproverPolicy {

	public const string AS_ADMIN = 'admin';
	public const string AS_FACILITATOR = 'facilitator';
	public const string AS_NATIONAL = 'national';

	public function __construct(
		private ChapterDirectory $chapters,
		private IGroupManager $groups,
		private NationalOrganizers $nationalOrganizers,
	) {
	}

	/**
	 * Why this person may decide about the member, or null when they may not.
	 *
	 * @return array{'as': string, chapter: string|null}|null
	 */
	public function capacity(string $deciderId, string $memberId): ?array {
		if ($deciderId === $memberId) {
			return null;
		}
		if ($this->groups->isAdmin($deciderId)) {
			return ['as' => self::AS_ADMIN, 'chapter' => null];
		}

		$memberChapters = $this->chapters->memberChapters($memberId);
		foreach ($this->chapters->facilitatedChapters($deciderId) as $slug) {
			if (in_array($slug, $memberChapters, true)) {
				return ['as' => self::AS_FACILITATOR, 'chapter' => $slug];
			}
		}

		if ($this->nationalOrganizers->isNationalOrganizer($deciderId)) {
			return ['as' => self::AS_NATIONAL, 'chapter' => null];
		}

		return null;
	}

	public function mayDecide(string $deciderId, string $memberId): bool {
		return $this->capacity($deciderId, $memberId) !== null;
	}

	/**
	 * Who should be told about something waiting for a decision about the member: the
	 * chapter facilitators, else the national organizers, else the admins. Never the member.
	 *
	 * @return list<string>
	 */
	public function recipients(string $memberId): array {
		$memberChapters = $this->chapters->memberChapters($memberId);

		$facilitators = [];
		foreach ($memberChapters as $slug) {
			$facilitators = array_merge($facilitators, $this->facilitatorsOf($slug));
		}
		$level = [
			$facilitators,
			$this->nationalOrganizers->users(),
			$this->usersOf('admin'),
		];
		foreach ($level as $users) {
			$users = array_values(array_unique(array_filter($users, fn (string $u) => $u !== $memberId)));
			if ($users !== []) {
				return $users;
			}
		}

		return [];
	}

	/**
	 * @return list<string>
	 */
	public function admins(string $exceptUserId): array {
		return array_values(array_filter($this->usersOf('admin'), fn (string $u) => $u !== $exceptUserId));
	}

	/**
	 * @return list<string>
	 */
	private function facilitatorsOf(string $slug): array {
		$chapter = $this->chapters->get($slug);

		return $chapter === null ? [] : $this->chapters->usersOf($chapter->facilitatorGroup);
	}

	/**
	 * @return list<string>
	 */
	private function usersOf(string $groupId): array {
		return $this->chapters->usersOf($groupId);
	}
}
