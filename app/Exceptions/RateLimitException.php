<?php

namespace App\Exceptions;

use RuntimeException;

class RateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter = 2)
    {
        parent::__construct('External service rate limit reached.');
    }
}
