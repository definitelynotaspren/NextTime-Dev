<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

/**
 * Values from a request body can be any JSON type. These keep a wrong type from reaching an entity
 * setter or new \DateTime(), where it would fail as a server error instead of being ignored.
 */
final class Input {

	/** Most rows any list returns in one page. */
	public const int MAX_LIMIT = 100;

	/** The value if it is text, otherwise null. */
	public static function string(mixed $value): ?string {
		return is_string($value) ? $value : null;
	}

	/** The value as a whole number if it is one (or numeric text), otherwise null. */
	public static function int(mixed $value): ?int {
		if (is_int($value)) {
			return $value;
		}
		return is_string($value) && ctype_digit($value) ? (int)$value : null;
	}

	/** A page size from 1 to MAX_LIMIT; anything missing or not a whole number gives the default. */
	public static function limit(mixed $value, int $default = 50): int {
		return max(1, min(self::MAX_LIMIT, self::int($value) ?? $default));
	}

	/** A starting row of 0 or more; anything missing or not a whole number gives 0. */
	public static function offset(mixed $value): int {
		return max(0, self::int($value) ?? 0);
	}
}
