<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $requiredPence = 0,
        public readonly int $balancePence = 0,
        string $message = 'Your balance must be more than 20p to run this. Subscribe to the platform plan for monthly credit value, or top up on the Billing page.',
    ) {
        parent::__construct($message);
    }
}
