<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\AppInfo\Application;
use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Schema\SchemaSpec;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Who may decide on something about a member (a claim, a large balance adjustment).
 *
 * Person P may decide about member U when P is not U and one of these holds:
 *  1. P is a Nextcloud admin, so the community can never be locked out.
 *  2. P is in the facilitator group of a chapter U belongs to. The facilitator group is enough on its
 *     own; P need not also be in the chapter's member group. Chapters are found by ChapterDirectory.
 *  3. None of U's chapters has a facilitator (or U has no chapter) and P is in the fallback group
 *     (default GSUS). The fallback never overrides a chapter that has its own facilitators.
 */
class ApproverPolicy {

	public const string AS_ADMIN = 'admin';
	public const string AS_FACILITATOR = 'facilitator';
	public const string AS_FALLBACK = 'fallback';

	public function __construct(
		private ChapterDirectory $chapters,
		private IGroupManager $groups,
		private IAppConfig $config,
	) {
	}

	public function fallbackGroup(): string {
		$group = trim($this->config->getValueString(Application::APP_ID, 'fallback_approver_group', SchemaSpec::GROUP_FALLBACK_DEFAULT));

		return $group === '' ? SchemaSpec::GROUP_FALLBACK_DEFAULT : $group;
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

		if ($this->groups->isInGroup($deciderId, $this->fallbackGroup()) && !$this->hasFacilitator($memberChapters, $memberId)) {
			return ['as' => self::AS_FALLBACK, 'chapter' => null];
		}

		return null;
	}

	public function mayDecide(string $deciderId, string $memberId): bool {
		return $this->capacity($deciderId, $memberId) !== null;
	}

	/**
	 * Who should be told about something waiting for a decision about the member: the
	 * chapter facilitators, else the fallback group, else the admins. Never the member.
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
			$this->usersOf($this->fallbackGroup()),
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
	 * @param list<string> $chapters chapter slugs the member belongs to
	 */
	private function hasFacilitator(array $chapters, string $memberId): bool {
		foreach ($chapters as $slug) {
			foreach ($this->facilitatorsOf($slug) as $user) {
				if ($user !== $memberId) {
					return true;
				}
			}
		}

		return false;
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
