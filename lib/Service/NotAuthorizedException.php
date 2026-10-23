<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use RuntimeException;

/**
 * The caller is not the person allowed to do this (for example someone other
 * than the requester accepting a volunteer). Controllers answer with HTTP 403.
 */
class NotAuthorizedException extends RuntimeException {
}
