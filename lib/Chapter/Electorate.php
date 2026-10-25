<?php

declare(strict_types=1);

namespace OCA\TimeBank\Chapter;

/**
 * Who votes on something about a member: a claim sent to a vote, or a large adjustment.
 *
 * The chapter members of the member's states: everyone in a state's member group or its facilitator
 * group (Decision 2 of the security plan). When the member has no state, or their states have fewer
 * than MIN_VOTERS people who could vote, the national organizers vote instead, so a small chapter is
 * never left with a vote it cannot finish.
 *
 * The member is never in it, nor anyone passed as excluded (for example whoever proposed it).
 * Group membership is read fresh on every call.
 */
class Electorate {

	public const int MIN_VOTERS = 3;

	public function __construct(
		private ChapterDirectory $chapters,
		private NationalOrganizers $nationalOrganizers,
	) {
	}

	/**
	 * @param list<string> $excluded people who may not vote, besides the member
	 * @return list<string>
	 */
	public function forMember(string $memberId, array $excluded = []): array {
		$excluded[] = $memberId;
		$state = self::without($this->stateVoters($memberId), $excluded);
		if (count($state) >= self::MIN_VOTERS) {
			return $state;
		}

		return self::without($this->nationalOrganizers->users(), $excluded);
	}

	/**
	 * @return list<string>
	 */
	private function stateVoters(string $memberId): array {
		$people = [];
		foreach ($this->chapters->memberChapters($memberId) as $slug) {
			$chapter = $this->chapters->get($slug);
			if ($chapter === null) {
				continue;
			}
			if ($chapter->memberGroup !== null) {
				$people = array_merge($people, $this->chapters->usersOf($chapter->memberGroup));
			}
			$people = array_merge($people, $this->chapters->usersOf($chapter->facilitatorGroup));
		}

		return $people;
	}

	/**
	 * @param list<string> $people
	 * @param list<string> $excluded
	 * @return list<string>
	 */
	private static function without(array $people, array $excluded): array {
		return array_values(array_unique(array_filter($people, fn (string $u) => !in_array($u, $excluded, true))));
	}
}
