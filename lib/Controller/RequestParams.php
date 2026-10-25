<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

/**
 * Text parameters read safely. A JSON body can send any type, and an array where text is expected
 * would otherwise reach trim() or a string parameter and fail as a 500 instead of a plain 400.
 */
trait RequestParams {

	/** The parameter trimmed, or '' when it is missing or not text. */
	private function textParam(string $key): string {
		$value = $this->request->getParam($key);
		return is_string($value) ? trim($value) : '';
	}

	/** The parameter trimmed, or null when it is missing, empty or not text. */
	private function optionalTextParam(string $key): ?string {
		$value = $this->textParam($key);
		return $value === '' ? null : $value;
	}
}
