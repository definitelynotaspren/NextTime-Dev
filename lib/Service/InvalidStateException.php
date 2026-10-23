<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use RuntimeException;

/**
 * A move the request or offer lifecycle does not allow right now (for example
 * completing a cancelled request). Controllers answer with HTTP 400.
 */
class InvalidStateException extends RuntimeException {
}
