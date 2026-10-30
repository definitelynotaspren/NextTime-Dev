<?php

declare(strict_types=1);

namespace OCA\TimeBank\Schema;

use OCP\AppFramework\Http;
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

	/** @return Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY */
	public function status(): int {
		return match ($this->getCode()) {
			403 => Http::STATUS_FORBIDDEN,
			404 => Http::STATUS_NOT_FOUND,
			409 => Http::STATUS_CONFLICT,
			default => Http::STATUS_UNPROCESSABLE_ENTITY,
		};
	}
}
