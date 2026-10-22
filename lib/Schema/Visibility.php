<?php

declare(strict_types=1);

namespace OCA\TimeBank\Schema;

/**
 * Decides whether a viewer may see a field value. Every read of layer data
 * goes through this rule via FieldPolicy.
 */
final class Visibility {

	public const PUBLIC = 'public';
	public const FACILITATOR = 'facilitator';
	public const CHAPTER = 'chapter';
	public const ORGANIZER = 'organizer';

	public static function allows(string $visibility, Viewer $viewer, ?string $rowChapter): bool {
		return match ($visibility) {
			self::PUBLIC => true,
			self::FACILITATOR => $viewer->isStaff(),
			self::CHAPTER => $viewer->canSeeChapter($rowChapter),
			self::ORGANIZER => $viewer->organizer,
			// Unknown or missing visibility fails closed to organizers.
			default => $viewer->organizer,
		};
	}
}
