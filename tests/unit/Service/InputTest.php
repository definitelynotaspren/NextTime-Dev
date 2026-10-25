<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Service\Input;
use PHPUnit\Framework\TestCase;

/**
 * A request body can carry any JSON type; a wrong one must be dropped, not passed to a setter.
 */
final class InputTest extends TestCase {

	public function testTextIsKeptAsIs(): void {
		$this->assertSame(' Portland ', Input::string(' Portland '));
		$this->assertSame('', Input::string(''));
	}

	public function testAnythingElseIsNotText(): void {
		$this->assertNull(Input::string(null));
		$this->assertNull(Input::string(['Portland']));
		$this->assertNull(Input::string(5));
	}

	public function testWholeNumbersAndDigitTextAreNumbers(): void {
		$this->assertSame(7, Input::int(7));
		$this->assertSame(7, Input::int('7'));
	}

	public function testOtherValuesAreNotNumbers(): void {
		$this->assertNull(Input::int(null));
		$this->assertNull(Input::int('7a'));
		$this->assertNull(Input::int('-1'));
		$this->assertNull(Input::int(1.5));
		$this->assertNull(Input::int([7]));
	}
}
