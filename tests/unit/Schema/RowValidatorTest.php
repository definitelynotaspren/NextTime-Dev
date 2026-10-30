<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Schema;

use OCA\TimeBank\Schema\RowValidator;
use OCA\TimeBank\Schema\SchemaSpec;
use PHPUnit\Framework\TestCase;

class RowValidatorTest extends TestCase {

	private function needsFields(): array {
		return SchemaSpec::layer('needs_offers')['fields'];
	}

	private function validNeed(): array {
		return [
			'need_or_offer' => 'need',
			'category' => 'food',
			'summary' => 'Need groceries this week',
			'zip' => '97055',
			'urgency' => 'this_week',
			'posted' => '2026-10-01',
			'expires' => '2026-11-01',
			'contact_route' => 'chapter_organizer',
			'status' => 'open',
		];
	}

	public function testAcceptsValidRow(): void {
		$result = RowValidator::validate($this->needsFields(), $this->validNeed());

		$this->assertSame([], $result['errors']);
		$this->assertFalse($result['publish']);
		$this->assertSame('97055', $result['data']['zip']);
	}

	public function testPublishDefaultsToNoAndOnlyYesPublishes(): void {
		$this->assertTrue(RowValidator::validate($this->needsFields(), $this->validNeed() + ['publish' => 'yes'])['publish']);
		$this->assertFalse(RowValidator::validate($this->needsFields(), $this->validNeed() + ['publish' => 'maybe'])['publish']);
	}

	public function testRejectsUnknownFields(): void {
		$result = RowValidator::validate($this->needsFields(), $this->validNeed() + ['ssn' => '123']);

		$this->assertContains('Unknown field: ssn', $result['errors']);
		$this->assertArrayNotHasKey('ssn', $result['data']);
	}

	public function testRequiresRequiredFields(): void {
		$input = $this->validNeed();
		unset($input['summary']);

		$this->assertContains('summary is required', RowValidator::validate($this->needsFields(), $input)['errors']);
	}

	public function testRejectsChoicesOutsideLookup(): void {
		$result = RowValidator::validate($this->needsFields(), ['category' => 'gold'] + $this->validNeed());

		$this->assertNotEmpty($result['errors']);
	}

	public function testRejectsMalformedZipAndDates(): void {
		$result = RowValidator::validate($this->needsFields(), [
			'zip' => '9705',
			'posted' => '10/01/2026',
		] + $this->validNeed());

		$this->assertContains('zip must be a 5-digit ZIP', $result['errors']);
		$this->assertContains('posted must be a date (YYYY-MM-DD)', $result['errors']);
	}

	public function testRejectsNonHttpUrls(): void {
		$fields = SchemaSpec::layer('actions')['fields'];
		$input = [
			'title' => 'Cleanup',
			'action_type' => 'cleanup',
			'zip' => '97055',
			'location_visibility' => 'area_only',
			'start' => '2026-11-02 10:00',
			'status' => 'scheduled',
			'rsvp_url' => 'javascript:alert(1)',
		];

		$this->assertContains('rsvp_url must be an http or https link', RowValidator::validate($fields, $input)['errors']);
	}
}
