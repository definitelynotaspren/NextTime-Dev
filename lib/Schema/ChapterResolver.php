<?php

declare(strict_types=1);

namespace OCA\TimeBank\Schema;

/**
 * Maps a ZIP code to the chapter that reviews rows in it.
 */
final class ChapterResolver {

	public static function forZip(?string $zip): ?string {
		if ($zip === null || !preg_match('/^\d{5}$/', $zip)) {
			return null;
		}

		foreach (SchemaSpec::chapters() as $slug => $chapter) {
			foreach ($chapter['zips'] as $pattern) {
				if (str_ends_with($pattern, '*')) {
					if (str_starts_with($zip, substr($pattern, 0, -1))) {
						return $slug;
					}
				} elseif ($zip === $pattern) {
					return $slug;
				}
			}
		}

		return null;
	}
}
