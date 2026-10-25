<?php

declare(strict_types=1);

namespace OCA\TimeBank\Schema;

/**
 * Checks organizer-entered row data against a layer's field definitions.
 * Pure: no database or Nextcloud calls.
 *
 * @psalm-import-type Field from SchemaSpec
 */
final class RowValidator {

	/**
	 * @param list<Field> $fields the layer's own fields (no system fields)
	 * @param array<string, mixed> $input
	 * @return array{publish: bool, data: array<string, mixed>, errors: list<string>}
	 */
	public static function validate(array $fields, array $input): array {
		$lookups = SchemaSpec::lookups();
		$known = [];
		foreach ($fields as $field) {
			$known[$field['name']] = $field;
		}

		$errors = [];
		$data = [];

		foreach ($input as $name => $raw) {
			if ($name === 'uid' || $name === 'publish') {
				continue;
			}
			if (!isset($known[$name])) {
				$errors[] = "Unknown field: $name";
				continue;
			}

			$value = self::normalize($known[$name], $raw, $lookups, $errors);
			if ($value !== null) {
				$data[$name] = $value;
			}
		}

		foreach ($fields as $field) {
			if ($field['required'] && !isset($data[$field['name']])) {
				$errors[] = $field['name'] . ' is required';
			}
		}

		$publish = ($input['publish'] ?? 'no') === 'yes';

		return ['publish' => $publish, 'data' => $data, 'errors' => $errors];
	}

	/**
	 * @param Field $field
	 * @param array<string, list<string>> $lookups
	 * @param list<string> $errors
	 */
	private static function normalize(array $field, mixed $raw, array $lookups, array &$errors): mixed {
		$name = $field['name'];
		$value = is_string($raw) ? trim($raw) : $raw;

		if ($value === null || $value === '') {
			return null;
		}

		switch ($field['type']) {
			case 'text':
				return (string)$value;

			case 'url':
				if (!is_string($value) || !preg_match('#^https?://#i', $value)) {
					$errors[] = "$name must be an http or https link";
					return null;
				}
				return $value;

			case 'zip':
				if (!is_string($value) || !preg_match('/^\d{5}$/', $value)) {
					$errors[] = "$name must be a 5-digit ZIP";
					return null;
				}
				return $value;

			case 'int':
				if (filter_var($value, FILTER_VALIDATE_INT) === false) {
					$errors[] = "$name must be a whole number";
					return null;
				}
				return (int)$value;

			case 'number':
				if (!is_numeric($value)) {
					$errors[] = "$name must be a number";
					return null;
				}
				return (float)$value;

			case 'date':
				if (!self::isDate((string)$value, ['Y-m-d'])) {
					$errors[] = "$name must be a date (YYYY-MM-DD)";
					return null;
				}
				return (string)$value;

			case 'datetime':
				if (!self::isDate((string)$value, ['Y-m-d H:i', 'Y-m-d\TH:i'])) {
					$errors[] = "$name must be a date and time (YYYY-MM-DD HH:MM)";
					return null;
				}
				return (string)$value;

			case 'bool':
			case 'choice':
				$allowed = $lookups[$field['choices'] ?? ''] ?? null;
				if ($allowed === null || !in_array((string)$value, $allowed, true)) {
					$errors[] = "$name must be one of: " . implode(', ', $allowed ?? []);
					return null;
				}
				return (string)$value;

			default:
				$errors[] = "$name has an unsupported type";
				return null;
		}
	}

	/** @param list<string> $formats */
	private static function isDate(string $value, array $formats): bool {
		foreach ($formats as $format) {
			$parsed = \DateTime::createFromFormat($format, $value);
			if ($parsed !== false && $parsed->format($format) === $value) {
				return true;
			}
		}

		return false;
	}
}
