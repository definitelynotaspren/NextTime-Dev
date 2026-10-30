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

	public function testAPageHoldsOneToAHundredRows(): void {
		$this->assertSame(50, Input::limit(null));
		$this->assertSame(20, Input::limit('20'));
		$this->assertSame(100, Input::limit('100000'));
		$this->assertSame(1, Input::limit('0'));
		$this->assertSame(50, Input::limit('-1'), 'not a whole number: the default');
		$this->assertSame(100, Input::limit(null, 500), 'even the default is capped');
	}

	public function testAnOffsetIsZeroOrMore(): void {
		$this->assertSame(0, Input::offset(null));
		$this->assertSame(0, Input::offset('-5'));
		$this->assertSame(40, Input::offset('40'));
		$this->assertSame(0, Input::offset(['40']));
	}
}
