<?php

declare(strict_types=1);

namespace OCA\TimeBank\Chapter;

/**
 * A state or chapter, discovered from Nextcloud groups. It exists because a facilitator
 * group named "<Name> Facilitator(s)" exists. Its members live in the group named "<Name>",
 * if there is one. Group ids (not names) are what role checks use.
 */
final class Chapter {

	/**
	 * @param string $slug lowercase, dashes: "new-york-city"
	 * @param string $name the name as admins see it: "New York City"
	 * @param string|null $memberGroup id of the group that says who belongs, if it exists
	 * @param string $facilitatorGroup id of the group whose members facilitate this chapter
	 * @param string|null $state two-letter state code when the name is a US state (or its code)
	 * @param list<string> $zipPrefixes ZIP prefixes that map here ahead of the state lookup
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $name,
		public readonly ?string $memberGroup,
		public readonly string $facilitatorGroup,
		public readonly ?string $state,
		public readonly array $zipPrefixes = [],
	) {
	}
}
