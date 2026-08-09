<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $requiredPence = 0,
        public readonly int $balancePence = 0,
        string $message = 'Insufficient usage credits.',
    ) {
        parent::__construct($message);
    }
}
