<?php

declare(strict_types=1);

namespace OCA\TimeBank\Schema;

/**
 * Who is asking, reduced to the roles the visibility rules care about.
 * Built once per request by ViewerResolver; never trusted from request input.
 */
final class Viewer {

	/**
	 * @param list<string> $chapters slugs of the chapters this viewer facilitates
	 */
	public function __construct(
		public readonly string $userId,
		public readonly bool $organizer,
		public readonly bool $facilitator,
		public readonly array $chapters = [],
	) {
	}

	/**
	 * A logged-in member with no organizer or facilitator role.
	 *
	 * @psalm-api
	 */
	public static function member(string $userId): self {
		return new self($userId, false, false, []);
	}

	public function isStaff(): bool {
		return $this->organizer || $this->facilitator;
	}

	public function canSeeChapter(?string $chapter): bool {
		if ($this->organizer) {
			return true;
		}

		return $chapter !== null && in_array($chapter, $this->chapters, true);
	}
}
