<?php

declare(strict_types=1);

namespace OCA\TimeBank\Chapter;

use OCA\TimeBank\AppInfo\Application;
use OCA\TimeBank\Geo\ZipStates;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;

/**
 * Finds the states and chapters from the Nextcloud groups that already exist, so an admin
 * creates nothing extra: a group "Colorado" (members) and a group "Colorado Facilitators"
 * (facilitators) make the Colorado chapter.
 *
 * Pairing uses the group's name as admins see it (its display name, or its id when it has
 * none), ignoring case, spaces, hyphens and underscores, and a trailing "Facilitator" or
 * "Facilitators". Every role check then uses the group id.
 *
 * Nothing is cached beyond one request, so removing someone from a facilitator group takes
 * effect on their next action.
 */
class ChapterDirectory {

	/** Where the ZIP prefixes of city chapters that are not states are kept, as JSON. */
	public const string CONFIG_ZIP_PREFIXES = 'chapter_zip_prefixes';

	/** Used when the setting is empty. Lowercase chapter name => ZIP prefixes. */
	private const array DEFAULT_ZIP_PREFIXES = [
		'new york city' => ['100', '101', '102', '103', '104', '111', '112', '113', '114', '116'],
	];

	/** @var array<string, Chapter>|null */
	private ?array $chapters = null;
	/** @var list<array{id: string, name: string}> */
	private array $unpaired = [];
	/** @var list<array{id: string, name: string, state: string}> */
	private array $stateGroupsWithoutFacilitators = [];

	public function __construct(
		private IGroupManager $groups,
		private IAppConfig $config,
	) {
	}

	/**
	 * @return array<string, Chapter> by slug
	 */
	public function all(): array {
		$this->discover();

		return $this->chapters ?? [];
	}

	public function get(string $slug): ?Chapter {
		return $this->all()[$slug] ?? null;
	}

	/**
	 * Chapters this person belongs to (their member group). Gives no powers.
	 *
	 * @return list<string> slugs
	 */
	public function memberChapters(string $userId): array {
		return $this->chaptersWhere($userId, fn (Chapter $c) => $c->memberGroup);
	}

	/**
	 * Chapters this person facilitates (their facilitator group, on its own).
	 *
	 * @return list<string> slugs
	 */
	public function facilitatedChapters(string $userId): array {
		return $this->chaptersWhere($userId, fn (Chapter $c) => $c->facilitatorGroup);
	}

	/**
	 * Which chapter reviews things in this ZIP: a city chapter's own prefixes first (longest
	 * match), then the chapter named after the ZIP's state. Null when no chapter covers it.
	 */
	public function chapterForZip(?string $zip): ?string {
		if ($zip === null || !preg_match('/^\d{5}$/', $zip)) {
			return null;
		}

		$best = null;
		$bestLength = 0;
		foreach ($this->all() as $chapter) {
			foreach ($chapter->zipPrefixes as $prefix) {
				if (str_starts_with($zip, $prefix) && strlen($prefix) > $bestLength) {
					$best = $chapter->slug;
					$bestLength = strlen($prefix);
				}
			}
		}
		if ($best !== null) {
			return $best;
		}

		$state = ZipStates::stateOf($zip);
		if ($state === null) {
			return null;
		}
		foreach ($this->all() as $chapter) {
			if ($chapter->state === $state) {
				return $chapter->slug;
			}
		}

		return null;
	}

	/**
	 * What the admin check page shows: the chapters found and the groups that look like they
	 * were meant to pair but did not.
	 *
	 * @return array{chapters: list<array<string, mixed>>, unpairedFacilitatorGroups: list<array{id: string, name: string}>, stateGroupsWithoutFacilitators: list<array{id: string, name: string, state: string}>}
	 */
	public function report(): array {
		$chapters = [];
		foreach ($this->all() as $chapter) {
			$facilitators = $this->usersOf($chapter->facilitatorGroup);
			$members = $chapter->memberGroup === null ? [] : $this->usersOf($chapter->memberGroup);
			$chapters[] = [
				'slug' => $chapter->slug,
				'name' => $chapter->name,
				'state' => $chapter->state,
				'zipPrefixes' => $chapter->zipPrefixes,
				'memberGroup' => $chapter->memberGroup,
				'facilitatorGroup' => $chapter->facilitatorGroup,
				'memberCount' => count($members),
				'facilitators' => $facilitators,
			];
		}

		return [
			'chapters' => $chapters,
			'unpairedFacilitatorGroups' => $this->unpaired,
			'stateGroupsWithoutFacilitators' => $this->stateGroupsWithoutFacilitators,
		];
	}

	/**
	 * @param callable(Chapter): ?string $groupOf
	 * @return list<string>
	 */
	private function chaptersWhere(string $userId, callable $groupOf): array {
		$slugs = [];
		foreach ($this->all() as $chapter) {
			$gid = $groupOf($chapter);
			if ($gid !== null && $this->groups->isInGroup($userId, $gid)) {
				$slugs[] = $chapter->slug;
			}
		}

		return $slugs;
	}

	/**
	 * @return list<string>
	 */
	public function usersOf(string $groupId): array {
		$group = $this->groups->get($groupId);
		if ($group === null) {
			return [];
		}

		return array_values(array_map(fn ($user) => $user->getUID(), $group->getUsers()));
	}

	private function discover(): void {
		if ($this->chapters !== null) {
			return;
		}

		/** @var array<string, IGroup> $byKey */
		$byKey = [];
		foreach ($this->groups->search('') as $group) {
			$byKey[self::key($this->label($group))] = $group;
		}

		$prefixes = $this->zipPrefixSetting();
		$chapters = [];
		$facilitatorKeys = [];
		foreach ($byKey as $key => $group) {
			if (!preg_match('/^(.+) facilitators?$/', $key, $m)) {
				continue;
			}
			$base = $m[1];
			$facilitatorKeys[$base] = true;
			$memberGroup = ($byKey[$base] ?? null)?->getGID();
			$state = self::stateFor($base);
			$cityPrefixes = $prefixes[$base] ?? [];
			if ($memberGroup === null && $state === null && $cityPrefixes === []) {
				// "Chapter Facilitators" and the like: not tied to any state or members.
				$this->unpaired[] = ['id' => $group->getGID(), 'name' => $this->label($group)];
				continue;
			}

			$name = $memberGroup !== null ? $this->label($byKey[$base]) : $this->stripFacilitator($this->label($group));
			$slug = self::slug($base);
			$chapters[$slug] = new Chapter($slug, $name, $memberGroup, $group->getGID(), $state, $cityPrefixes);
		}

		foreach ($byKey as $key => $group) {
			$state = self::stateFor($key);
			if ($state !== null && !isset($facilitatorKeys[$key])) {
				$this->stateGroupsWithoutFacilitators[] = ['id' => $group->getGID(), 'name' => $this->label($group), 'state' => $state];
			}
		}

		$this->chapters = $chapters;
	}

	private function label(IGroup $group): string {
		$name = trim($group->getDisplayName());

		return $name !== '' ? $name : $group->getGID();
	}

	private function stripFacilitator(string $label): string {
		return trim(preg_replace('/[\s_\-]+facilitators?$/i', '', $label) ?? $label);
	}

	/** Lowercase, with runs of spaces, hyphens and underscores as one space. */
	private static function key(string $name): string {
		return trim(preg_replace('/[\s_\-]+/', ' ', mb_strtolower($name)) ?? '');
	}

	private static function slug(string $key): string {
		return trim(preg_replace('/[^a-z0-9]+/', '-', $key) ?? '', '-');
	}

	/** Two-letter code when the (already normalised) name is a US state or its code. */
	private static function stateFor(string $key): ?string {
		foreach (ZipStates::names() as $code => $name) {
			if ($key === self::key($name) || $key === strtolower($code)) {
				return $code;
			}
		}

		return null;
	}

	/**
	 * @return array<string, list<string>> normalised chapter name => ZIP prefixes
	 */
	private function zipPrefixSetting(): array {
		$map = self::DEFAULT_ZIP_PREFIXES;
		$json = $this->config->getValueString(Application::APP_ID, self::CONFIG_ZIP_PREFIXES, '');
		$custom = $json === '' ? null : json_decode($json, true);
		if (is_array($custom)) {
			$map = [];
			foreach ($custom as $name => $list) {
				if (is_string($name) && is_array($list)) {
					$map[self::key($name)] = array_values(array_filter(array_map(fn (mixed $p): string => is_scalar($p) ? (string)$p : '', $list), fn (string $p) => preg_match('/^\d{1,5}$/', $p) === 1));
				}
			}
		}

		return $map;
	}
}
