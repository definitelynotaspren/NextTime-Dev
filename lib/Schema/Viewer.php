<?php

declare(strict_types=1);

namespace OCA\TimeBank\Schema;

/**
 * Who is asking, reduced to the roles the permission rules care about.
 * Built once per request by ViewerResolver; never trusted from request input.
 *
 * Roles stack: every logged-in user is a member; a chapter member is also in a state (or city)
 * group or its facilitator group; a facilitator facilitates the chapters in $facilitatorOf;
 * a national organizer acts everywhere.
 */
final class Viewer {

	/**
	 * @param list<string> $facilitatorOf slugs of the chapters this viewer facilitates
	 */
	public function __construct(
		public readonly string $userId,
		public readonly bool $nationalOrganizer,
		public readonly bool $facilitator,
		public readonly array $facilitatorOf = [],
		public readonly bool $chapterMember = false,
	) {
	}

	/**
	 * A logged-in member with no chapter or role.
	 *
	 * @psalm-api
	 */
	public static function member(string $userId): self {
		return new self($userId, false, false, []);
	}

	public function isStaff(): bool {
		return $this->nationalOrganizer || $this->facilitator;
	}

	public function canSeeChapter(?string $chapter): bool {
		if ($this->nationalOrganizer) {
			return true;
		}

		return $chapter !== null && in_array($chapter, $this->facilitatorOf, true);
	}
}
