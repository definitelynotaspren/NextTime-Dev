<?php

declare(strict_types=1);

namespace OCA\TimeBank\Chapter;

use OCA\TimeBank\AppInfo\Application;
use OCA\TimeBank\Schema\SchemaSpec;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * The one national organizer role: everyone in the "National Organizers" group (the setting
 * national_organizer_group names another). They act nationwide: decide claims and adjustments for
 * any member but themselves, and see every organizer-desk row.
 *
 * The two groups this role replaces, `timebank-organizer` and the fallback approver group
 * (setting fallback_approver_group, default `GSUS`), still count until an admin moves their
 * members over. The chapter check page lists who is still only in one of them.
 *
 * Like ChapterDirectory, the group is found by id or by the name admins see, and membership is
 * read fresh on every request.
 */
class NationalOrganizers {

	public const string CONFIG_GROUP = 'national_organizer_group';
	public const string CONFIG_LEGACY_FALLBACK = 'fallback_approver_group';

	public function __construct(
		private IGroupManager $groups,
		private IAppConfig $config,
	) {
	}

	/** The group name as configured (the default when unset or blank). */
	public function groupName(): string {
		$name = trim($this->config->getValueString(Application::APP_ID, self::CONFIG_GROUP, SchemaSpec::GROUP_NATIONAL_DEFAULT));

		return $name === '' ? SchemaSpec::GROUP_NATIONAL_DEFAULT : $name;
	}

	/** Id of the national organizer group, or null when no group has that id or name yet. */
	public function groupId(): ?string {
		$name = $this->groupName();
		if ($this->groups->groupExists($name)) {
			return $name;
		}
		$wanted = self::key($name);
		foreach ($this->groups->search($name) as $group) {
			if (self::key($group->getDisplayName()) === $wanted) {
				return $group->getGID();
			}
		}

		return null;
	}

	/**
	 * The groups that are being retired into the national organizer group.
	 *
	 * @return list<string>
	 */
	public function legacyGroupIds(): array {
		$fallback = trim($this->config->getValueString(Application::APP_ID, self::CONFIG_LEGACY_FALLBACK, SchemaSpec::GROUP_FALLBACK_DEFAULT));
		$ids = [SchemaSpec::GROUP_ORGANIZER, $fallback === '' ? SchemaSpec::GROUP_FALLBACK_DEFAULT : $fallback];
		$current = $this->groupId();

		return array_values(array_unique(array_filter($ids, fn (string $gid) => $gid !== $current)));
	}

	public function isNationalOrganizer(string $userId): bool {
		foreach ($this->groupIds() as $gid) {
			if ($this->groups->isInGroup($userId, $gid)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Everyone with the role, from the new group and the legacy ones.
	 *
	 * @return list<string>
	 */
	public function users(): array {
		$users = [];
		foreach ($this->groupIds() as $gid) {
			$users = array_merge($users, $this->usersOf($gid));
		}

		return array_values(array_unique($users));
	}

	/**
	 * For the admin check page: the group and who still holds the role only through a legacy group.
	 *
	 * @return array{groupName: string, groupId: string|null, members: list<string>, legacyOnly: list<array{group: string, users: list<string>}>}
	 */
	public function report(): array {
		$gid = $this->groupId();
		$members = $gid === null ? [] : $this->usersOf($gid);
		$legacy = [];
		foreach ($this->legacyGroupIds() as $legacyGid) {
			$only = array_values(array_diff($this->usersOf($legacyGid), $members));
			if ($only !== []) {
				$legacy[] = ['group' => $legacyGid, 'users' => $only];
			}
		}

		return ['groupName' => $this->groupName(), 'groupId' => $gid, 'members' => $members, 'legacyOnly' => $legacy];
	}

	/**
	 * @return list<string>
	 */
	private function groupIds(): array {
		$gid = $this->groupId();

		return $gid === null ? $this->legacyGroupIds() : [$gid, ...$this->legacyGroupIds()];
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

	private static function key(string $name): string {
		return trim(preg_replace('/[\s_\-]+/', ' ', mb_strtolower($name)) ?? '');
	}
}
