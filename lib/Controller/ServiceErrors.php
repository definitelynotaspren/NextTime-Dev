<?php

declare(strict_types=1);

namespace OCA\TimeBank\Controller;

use OCA\TimeBank\Service\NotAuthorizedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/**
 * Maps service exceptions to HTTP responses: 404 for a missing record, 403 when
 * the caller may not do it, and 400 (with a plain message) for anything the
 * lifecycle rules refuse.
 */
trait ServiceErrors {

	private function errorResponse(\Throwable $e): DataResponse {
		if ($e instanceof DoesNotExistException) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		if ($e instanceof NotAuthorizedException) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
	}
}
