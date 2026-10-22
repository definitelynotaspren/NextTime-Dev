<?php

declare(strict_types=1);

namespace OCA\TimeBank\Geo;

/**
 * Parses, validates and coarsens "lat, lng" points.
 *
 * Coordinates are stored as WGS84 latitude/longitude. Anything shown to
 * people who are not organizers must go through toPublic(), which rounds the
 * point to an approximate area.
 */
final class Coordinates {

	/** Decimal places kept for public output (2 ≈ 1 km, an approximate area). */
	public const PUBLIC_PRECISION = 2;

	/**
	 * Parses "lat, lng" text. Returns [lat, lng] or null if the text is not a valid pair.
	 *
	 * @return array{0: float, 1: float}|null
	 */
	public static function parseText(?string $text): ?array {
		if ($text === null || trim($text) === '') {
			return null;
		}

		$parts = explode(',', $text);
		if (count($parts) !== 2) {
			return null;
		}

		$lat = trim($parts[0]);
		$lng = trim($parts[1]);
		if (!is_numeric($lat) || !is_numeric($lng)) {
			return null;
		}

		return self::validate((float)$lat, (float)$lng);
	}

	/**
	 * @return array{0: float, 1: float}|null [lat, lng] if in range, otherwise null
	 */
	public static function validate(float $lat, float $lng): ?array {
		if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
			return null;
		}

		return [$lat, $lng];
	}

	/**
	 * Coarsened point as GeoJSON position [lng, lat].
	 *
	 * @return array{0: float, 1: float}
	 */
	public static function toPublic(float $lat, float $lng): array {
		return [
			round($lng, self::PUBLIC_PRECISION),
			round($lat, self::PUBLIC_PRECISION),
		];
	}
}
