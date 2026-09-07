<?php

declare(strict_types=1);

namespace OwnRun;

final class LoginThrottledException extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        string $message = 'Too many login attempts. Try again later.',
    ) {
        parent::__construct($message);
    }
}
