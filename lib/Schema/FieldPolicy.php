<?php

declare(strict_types=1);

namespace OCA\TimeBank\Schema;

/**
 * Strips every field the viewer may not see. Hidden fields are removed, not
 * nulled, so their names do not leak either. Keys the spec does not declare
 * are treated as organizer-only.
 *
 * @psalm-import-type Field from SchemaSpec
 */
final class FieldPolicy {

	/**
	 * @param list<Field> $fields field definitions from SchemaSpec
	 * @param array<string, mixed> $payload field values to filter
	 * @return array<string, mixed>
	 */
	public static function filter(array $fields, array $payload, Viewer $viewer, ?string $rowChapter): array {
		$visibility = [];
		foreach ($fields as $field) {
			$visibility[$field['name']] = $field['visibility'];
		}

		$visible = [];
		foreach ($payload as $name => $value) {
			$level = $visibility[$name] ?? Visibility::ORGANIZER;
			if (Visibility::allows($level, $viewer, $rowChapter)) {
				$visible[$name] = $value;
			}
		}

		return $visible;
	}
}
