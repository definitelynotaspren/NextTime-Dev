<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Intake;

use OCA\TimeBank\Intake\FormsIntake;
use OCA\TimeBank\Service\InvalidStateException;
use PHPUnit\Framework\TestCase;

/**
 * Which pasted links name a form, and which are refused with a message that says what to paste.
 */
final class FormsLinkTest extends TestCase {

	public function testTheShareLinkAndTheFormAddressesGiveTheCode(): void {
		foreach ([
			'https://cloud.example.org/index.php/apps/forms/s/AbCd1234EfGh5678',
			'https://cloud.example.org/index.php/apps/forms/AbCd1234EfGh5678',
			'https://cloud.example.org/index.php/apps/forms/AbCd1234EfGh5678/edit',
			'https://cloud.example.org/apps/forms/AbCd1234EfGh5678/results?x=1#top',
			'https://CLOUD.example.org:8443/index.php/apps/forms/s/AbCd1234EfGh5678/',
			'  https://cloud.example.org/index.php/apps/forms/s/AbCd1234EfGh5678  ',
		] as $link) {
			$this->assertSame('AbCd1234EfGh5678', FormsIntake::hashFromLink($link, 'cloud.example.org'), $link);
		}
	}

	public function testTheBareCodeWorks(): void {
		$this->assertSame('AbCd1234EfGh5678', FormsIntake::hashFromLink('AbCd1234EfGh5678', 'cloud.example.org'));
	}

	public function testALinkOnAnotherServerIsRefusedAndNamesBothServers(): void {
		try {
			FormsIntake::hashFromLink('https://docs.google.com/forms/d/e/1FAIpQLSdxyz/viewform', 'cloud.example.org');
			$this->fail('Expected a refusal');
		} catch (InvalidStateException $e) {
			$this->assertStringContainsString('docs.google.com', $e->getMessage());
			$this->assertStringContainsString('cloud.example.org', $e->getMessage());
		}
	}

	public function testLinksThatAreNotToAFormAreRefused(): void {
		foreach ([
			'https://cloud.example.org/index.php/apps/forms/',
			'https://cloud.example.org/index.php/apps/files/',
			'https://cloud.example.org/index.php/apps/forms/s/abc',
		] as $link) {
			try {
				FormsIntake::hashFromLink($link, 'cloud.example.org');
				$this->fail("Should refuse $link");
			} catch (InvalidStateException $e) {
				$this->assertStringContainsString('not to a form', $e->getMessage());
			}
		}
	}

	public function testSomethingThatIsNotALinkGetsAnExample(): void {
		$this->expectException(InvalidStateException::class);
		$this->expectExceptionMessage('apps/forms/s/');
		FormsIntake::hashFromLink('my needs form', 'cloud.example.org');
	}
}
