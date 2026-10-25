<?php

declare(strict_types=1);

namespace OCA\TimeBank\Geo;

/**
 * The center of each ZIP code area, from the Census Bureau's ZCTA gazetteer (2023, public domain),
 * bundled in data/zip-centers.txt so no lookup leaves the server. Rebuild it with
 * scripts/generate-zip-centers.php.
 *
 * Requests shown "zip only" are placed on the map here, so the pin says no more than the ZIP does.
 *
 * The file is sorted, fixed-width lines ("ZZZZZ LLLLLLLL GGGGGGGGG\n"), searched on disk by halving,
 * so a lookup reads about 16 lines and never loads the whole table.
 */
final class ZipCenters {

	private const int LINE = 25;

	/** @var array<string, array{0: float, 1: float}|null> */
	private static array $cache = [];

	/**
	 * Accepts a 5-digit ZIP or ZIP+4 and returns its first five digits, or null when it is
	 * neither, or is not a ZIP of any state.
	 */
	public static function normalize(mixed $zip): ?string {
		if (!is_string($zip) && !is_int($zip)) {
			return null;
		}
		$zip = trim((string)$zip);
		if (!preg_match('/^(\d{5})(-\d{4})?$/', $zip, $m)) {
			return null;
		}

		return ZipStates::stateOf($m[1]) === null ? null : $m[1];
	}

	/**
	 * [latitude, longitude] of the ZIP area's center, or null for an unknown ZIP (for example a
	 * PO-box-only ZIP, which has no area).
	 *
	 * @return array{0: float, 1: float}|null
	 */
	public static function centerOf(?string $zip): ?array {
		if ($zip === null || !preg_match('/^\d{5}$/', $zip)) {
			return null;
		}
		if (array_key_exists($zip, self::$cache)) {
			return self::$cache[$zip];
		}

		return self::$cache[$zip] = self::search($zip);
	}

	/**
	 * @return array{0: float, 1: float}|null
	 */
	private static function search(string $zip): ?array {
		$path = __DIR__ . '/data/zip-centers.txt';
		$size = @filesize($path);
		$handle = @fopen($path, 'rb');
		if ($size === false || $handle === false) {
			return null;
		}

		try {
			$low = 0;
			$high = intdiv($size, self::LINE) - 1;
			while ($low <= $high) {
				$mid = intdiv($low + $high, 2);
				fseek($handle, $mid * self::LINE);
				$line = (string)fread($handle, self::LINE);
				$cmp = strcmp(substr($line, 0, 5), $zip);
				if ($cmp === 0) {
					return [(float)substr($line, 6, 8), (float)substr($line, 15, 9)];
				}
				if ($cmp < 0) {
					$low = $mid + 1;
				} else {
					$high = $mid - 1;
				}
			}

			return null;
		} finally {
			fclose($handle);
		}
	}
}
