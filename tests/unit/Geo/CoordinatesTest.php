<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Geo;

use OCA\TimeBank\Geo\Coordinates;
use PHPUnit\Framework\TestCase;

class CoordinatesTest extends TestCase {

	public function testParsesLatLngText(): void {
		$this->assertSame([45.4215, -122.7528], Coordinates::parseText('45.4215, -122.7528'));
		$this->assertSame([45.4215, -122.7528], Coordinates::parseText('45.4215,-122.7528'));
	}

	public function testRejectsFreeTextAddresses(): void {
		$this->assertNull(Coordinates::parseText('123 Main Street'));
		$this->assertNull(Coordinates::parseText('45.4, -122.7, Portland'));
		$this->assertNull(Coordinates::parseText(''));
		$this->assertNull(Coordinates::parseText(null));
	}

	public function testRejectsOutOfRangeValues(): void {
		$this->assertNull(Coordinates::parseText('91, 0'));
		$this->assertNull(Coordinates::parseText('0, 181'));
		$this->assertNull(Coordinates::validate(-90.5, 0.0));
	}

	public function testPublicPointIsCoarsened(): void {
		[$lng, $lat] = Coordinates::toPublic(45.421537, -122.752811);

		$this->assertSame(-122.75, $lng);
		$this->assertSame(45.42, $lat);
	}
}
