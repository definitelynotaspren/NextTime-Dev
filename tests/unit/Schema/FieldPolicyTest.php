<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Schema;

use OCA\TimeBank\Schema\FieldPolicy;
use OCA\TimeBank\Schema\SchemaSpec;
use OCA\TimeBank\Schema\Viewer;
use PHPUnit\Framework\TestCase;

class FieldPolicyTest extends TestCase {

	/** A value for every field name in every layer, so filtering is tested exhaustively. */
	private function fullPayload(string $layer): array {
		$payload = [];
		foreach (SchemaSpec::allFields($layer) as $field) {
			$payload[$field['name']] = 'value-' . $field['name'];
		}

		return $payload;
	}

	private function fieldsWithVisibility(string $layer, string $visibility): array {
		$names = [];
		foreach (SchemaSpec::allFields($layer) as $field) {
			if ($field['visibility'] === $visibility) {
				$names[] = $field['name'];
			}
		}

		return $names;
	}

	public function testPublicMemberSeesOnlyPublicFieldsInEveryLayer(): void {
		foreach (array_keys(SchemaSpec::layers()) as $layer) {
			$visible = FieldPolicy::filter(
				SchemaSpec::allFields($layer),
				$this->fullPayload($layer),
				Viewer::member('someone'),
				'mt-hood',
			);

			$this->assertSame(
				array_values(array_unique($this->fieldsWithVisibility($layer, 'public'))),
				array_values(array_keys($visible)),
				"member saw non-public fields in $layer",
			);
		}
	}

	public function testOrganizerSeesEveryDeclaredField(): void {
		foreach (array_keys(SchemaSpec::layers()) as $layer) {
			$visible = FieldPolicy::filter(
				SchemaSpec::allFields($layer),
				$this->fullPayload($layer),
				new Viewer('org', true, false, []),
				null,
			);

			$declared = array_column(SchemaSpec::allFields($layer), 'name');
			$this->assertSame($declared, array_keys($visible), "organizer missed fields in $layer");
		}
	}

	public function testFacilitatorDoesNotSeeOrganizerOnlyFields(): void {
		$visible = FieldPolicy::filter(
			SchemaSpec::allFields('needs_offers'),
			$this->fullPayload('needs_offers'),
			new Viewer('fac', false, true, []),
			null,
		);

		$this->assertArrayNotHasKey('lat', $visible);
		$this->assertArrayNotHasKey('lon', $visible);
		$this->assertArrayNotHasKey('requester_contact', $visible);
		$this->assertArrayHasKey('summary', $visible);
	}

	public function testChapterFieldsShowOnlyToThatChapterFacilitator(): void {
		$payload = $this->fullPayload('resources');
		$fields = SchemaSpec::allFields('resources');

		$sameChapter = FieldPolicy::filter($fields, $payload, new Viewer('f1', false, true, ['mt-hood']), 'mt-hood');
		$otherChapter = FieldPolicy::filter($fields, $payload, new Viewer('f2', false, true, ['gresham']), 'mt-hood');

		$this->assertArrayHasKey('contact_internal', $sameChapter);
		$this->assertArrayNotHasKey('contact_internal', $otherChapter);
		$this->assertArrayNotHasKey('contact_internal', FieldPolicy::filter($fields, $payload, new Viewer('f3', false, true, []), null));
	}

	public function testUndeclaredFieldsFailClosed(): void {
		$visible = FieldPolicy::filter(
			SchemaSpec::allFields('needs_offers'),
			['summary' => 'ok', 'secret_column' => 'leak'],
			Viewer::member('someone'),
			null,
		);

		$this->assertSame(['summary' => 'ok'], $visible);
	}

	public function testHiddenFieldsAreRemovedNotNulled(): void {
		$visible = FieldPolicy::filter(
			SchemaSpec::allFields('needs_offers'),
			['summary' => 'ok', 'requester_contact' => 'call me'],
			Viewer::member('someone'),
			null,
		);

		$this->assertArrayNotHasKey('requester_contact', $visible);
	}
}
