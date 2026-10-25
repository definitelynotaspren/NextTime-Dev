<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

/**
 * Values from a request body can be any JSON type. These keep a wrong type from reaching an entity
 * setter or new \DateTime(), where it would fail as a server error instead of being ignored.
 */
final class Input {

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
}
