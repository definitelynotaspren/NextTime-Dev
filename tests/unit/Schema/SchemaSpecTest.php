<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Schema;

use OCA\TimeBank\Schema\SchemaSpec;
use OCA\TimeBank\Schema\Visibility;
use PHPUnit\Framework\TestCase;

/**
 * Guards the spec itself. A change that makes a sensitive field public, or
 * that breaks a lookup reference, fails here before it reaches a user.
 */
class SchemaSpecTest extends TestCase {

	private const KNOWN_VISIBILITY = ['public', 'facilitator', 'chapter', 'organizer'];

	public function testSensitiveFieldsAreNeverPublic(): void {
		$checked = 0;
		foreach (SchemaSpec::layers() as $name => $layer) {
			foreach (SchemaSpec::allFields($name) as $field) {
				if (!in_array($field['name'], SchemaSpec::SENSITIVE_FIELDS, true)) {
					continue;
				}
				$checked++;
				$this->assertNotSame(
					Visibility::PUBLIC,
					$field['visibility'],
					"{$name}.{$field['name']} is sensitive but public",
				);
			}
		}

		$this->assertGreaterThan(0, $checked, 'expected sensitive fields to be present in the spec');
	}

	public function testEveryFieldHasKnownVisibility(): void {
		foreach (SchemaSpec::layers() as $name => $layer) {
			foreach (SchemaSpec::allFields($name) as $field) {
				$this->assertContains(
					$field['visibility'],
					self::KNOWN_VISIBILITY,
					"{$name}.{$field['name']} has unknown visibility",
				);
			}
		}
	}

	public function testChoiceFieldsReferenceExistingLookups(): void {
		$lookups = SchemaSpec::lookups();
		foreach (SchemaSpec::layers() as $name => $layer) {
			foreach (SchemaSpec::allFields($name) as $field) {
				if ($field['choices'] === null) {
					continue;
				}
				$this->assertArrayHasKey(
					$field['choices'],
					$lookups,
					"{$name}.{$field['name']} uses missing lookup {$field['choices']}",
				);
			}
		}
	}

	public function testUidPrefixesAreUnique(): void {
		$prefixes = [];
		foreach (SchemaSpec::layers() as $name => $layer) {
			$prefixes[] = $layer['uid_prefix'];
		}

		$this->assertSame(count($prefixes), count(array_unique($prefixes)));
	}

	public function testSurveyIsAggregateOnly(): void {
		$survey = SchemaSpec::layer('survey');

		$this->assertNotNull($survey);
		$this->assertTrue($survey['aggregate_only']);
		$this->assertSame(5, $survey['min_count']);
	}

	public function testOnlyPointSnappedLayersAreFeedEligible(): void {
		$feedable = [];
		foreach (SchemaSpec::layers() as $name => $layer) {
			if (($layer['geometry'] ?? '') === 'point_snapped') {
				$feedable[] = $name;
			}
		}

		sort($feedable);
		$this->assertSame(['issue_reports', 'needs_offers'], $feedable);
	}
}
