<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\AppInfo\Application;
use OCA\TimeBank\Schema\SchemaSpec;
use OCP\IConfig;
use OCP\IGroupManager;

/**
 * Who may decide on something about a member (a claim, a large balance adjustment).
 *
 * Person P may decide about member U when P is not U and one of these holds:
 *  1. P is a Nextcloud admin, so the community can never be locked out.
 *  2. For a chapter U belongs to, P is in both the chapter's member group and its facilitator group.
 *  3. None of U's chapters has a facilitator (or U has no chapter) and P is in the fallback group
 *     (default GSUS). The fallback never overrides a chapter that has its own facilitators.
 */
class ApproverPolicy {

	public const AS_ADMIN = 'admin';
	public const AS_FACILITATOR = 'facilitator';
	public const AS_FALLBACK = 'fallback';

	public function __construct(
		private ViewerResolver $viewers,
		private IGroupManager $groups,
		private IConfig $config,
	) {
	}

	public function fallbackGroup(): string {
		$group = trim($this->config->getAppValue(Application::APP_ID, 'fallback_approver_group', SchemaSpec::GROUP_FALLBACK_DEFAULT));

		return $group === '' ? SchemaSpec::GROUP_FALLBACK_DEFAULT : $group;
	}

	/**
	 * Why this person may decide about the member, or null when they may not.
	 *
	 * @return array{as: string, chapter: string|null}|null
	 */
	public function capacity(string $deciderId, string $memberId): ?array {
		if ($deciderId === $memberId) {
			return null;
		}
		if ($this->groups->isAdmin($deciderId)) {
			return ['as' => self::AS_ADMIN, 'chapter' => null];
		}

		$memberChapters = $this->viewers->memberChapters($memberId);
		foreach ($this->viewers->facilitatedChapters($deciderId) as $slug) {
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
		$memberChapters = $this->viewers->memberChapters($memberId);

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
		$chapter = SchemaSpec::chapters()[$slug] ?? null;
		if ($chapter === null) {
			return [];
		}

		return array_values(array_filter(
			$this->usersOf($chapter['facilitator_group']),
			fn (string $user) => $this->groups->isInGroup($user, $chapter['group']),
		));
	}

	/**
	 * @return list<string>
	 */
	private function usersOf(string $groupId): array {
		$group = $this->groups->get($groupId);
		if ($group === null) {
			return [];
		}

		return array_values(array_map(fn ($user) => $user->getUID(), $group->getUsers()));
	}
}
