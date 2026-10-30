<?php

declare(strict_types=1);

namespace OCA\TimeBank\Intake;

use OCA\TimeBank\Schema\SchemaSpec;

/**
 * Turns the answers of one Nextcloud Forms submission into the input of a desk row. Pure: no
 * database or Nextcloud calls.
 *
 * A question feeds a field when its internal name, or its text, matches the field's key once
 * case, spaces and punctuation are ignored ("Need or offer" feeds need_or_offer). Questions that
 * match no field are ignored and their answers are never stored, so a form can ask for things
 * NextTime has no place for without them landing anywhere.
 *
 * @psalm-import-type Field from SchemaSpec
 */
final class FormsMapper {

	/** Fields filled in when the form does not ask. */
	public const DEFAULT_EXPIRY_DAYS = 30;

	/** "Tech help" and "tech_help" are the same. */
	public static function key(string $text): string {
		return trim(preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($text)) ?? '', '_');
	}

	/**
	 * @param list<array{id: int, text: string, name: ?string}> $questions
	 * @param list<Field> $fields the layer's own fields
	 * @return array{map: array<int, string>, ignored: list<string>}
	 */
	public static function mapQuestions(array $questions, array $fields): array {
		$known = array_column($fields, 'name');
		$map = [];
		$ignored = [];
		foreach ($questions as $question) {
			$field = null;
			foreach ([$question['name'] ?? '', $question['text']] as $candidate) {
				$key = self::key($candidate);
				if ($key !== '' && in_array($key, $known, true) && !in_array($key, $map, true)) {
					$field = $key;
					break;
				}
			}
			if ($field === null) {
				$ignored[] = $question['text'];
			} else {
				$map[$question['id']] = $field;
			}
		}

		return ['map' => $map, 'ignored' => $ignored];
	}

	/**
	 * Fields a required answer would be missing for, which no default covers.
	 *
	 * @param list<Field> $fields
	 * @param array<int, string> $map question id => field
	 * @return list<string>
	 */
	public static function missingRequired(array $fields, array $map): array {
		$missing = [];
		foreach ($fields as $field) {
			if ($field['required'] && !in_array($field['name'], $map, true) && !in_array($field['name'], ['posted', 'expires', 'status', 'last_verified'], true)) {
				$missing[] = $field['name'];
			}
		}

		return $missing;
	}

	/**
	 * @param list<Field> $fields the layer's own fields
	 * @param array<int, string> $map question id => field
	 * @param array<int, list<string>> $answers question id => answers (several for multiple choice)
	 * @return array<string, mixed> input for RowValidator::validate
	 */
	public static function buildInput(array $fields, array $map, array $answers, int $submittedAt): array {
		$byName = [];
		foreach ($fields as $field) {
			$byName[$field['name']] = $field;
		}
		$lookups = SchemaSpec::lookups();

		$input = [];
		foreach ($map as $questionId => $name) {
			$given = array_values(array_filter(array_map('trim', $answers[$questionId] ?? []), fn (string $a) => $a !== ''));
			if ($given === []) {
				continue;
			}
			$field = $byName[$name];
			$value = $given[0];
			if (in_array($field['type'], ['choice', 'bool'], true)) {
				$value = self::choice($value, $lookups[$field['choices'] ?? ''] ?? []);
			} elseif ($field['type'] === 'text' && count($given) > 1) {
				$value = implode(', ', $given);
			}
			$input[$name] = $value;
		}

		$posted = gmdate('Y-m-d', $submittedAt);
		$defaults = [
			'posted' => $posted,
			'expires' => gmdate('Y-m-d', $submittedAt + self::DEFAULT_EXPIRY_DAYS * 86400),
			'last_verified' => $posted,
		];
		foreach ($defaults as $name => $value) {
			if (isset($byName[$name]) && !isset($input[$name])) {
				$input[$name] = $value;
			}
		}
		// A new row starts at the first status of its lookup (open).
		if (isset($byName['status']) && !isset($input['status'])) {
			$allowed = $lookups[$byName['status']['choices'] ?? ''] ?? [];
			if ($allowed !== []) {
				$input['status'] = $allowed[0];
			}
		}

		// Whatever the form says, a phone submission is never published by itself.
		$input['publish'] = 'no';

		return $input;
	}

	/**
	 * @param list<string> $allowed
	 */
	private static function choice(string $answer, array $allowed): string {
		$key = self::key($answer);
		foreach ($allowed as $candidate) {
			if ($key === $candidate || $key === self::key($candidate)) {
				return $candidate;
			}
		}

		// "Transportation" for transport: accept a single clear prefix match either way round.
		$near = [];
		foreach ($allowed as $candidate) {
			$c = self::key($candidate);
			if (strlen($key) >= 4 && strlen($c) >= 4 && (str_starts_with($key, $c) || str_starts_with($c, $key))) {
				$near[] = $candidate;
			}
		}
		if (count($near) === 1) {
			return $near[0];
		}

		return $key;
	}
}
