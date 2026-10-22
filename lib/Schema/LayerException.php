<?php

declare(strict_types=1);

namespace OCA\TimeBank\Schema;

use RuntimeException;

/**
 * A rejected layer operation. The code is the HTTP status to return.
 */
class LayerException extends RuntimeException {

	public static function forbidden(string $message): self {
		return new self($message, 403);
	}

	public static function notFound(string $message): self {
		return new self($message, 404);
	}

	public static function conflict(string $message): self {
		return new self($message, 409);
	}

	public static function invalid(string $message): self {
		return new self($message, 422);
	}
}
