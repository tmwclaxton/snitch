<?php

namespace App\Exceptions;

use RuntimeException;

class PlatformSubscriptionRequiredException extends RuntimeException
{
    public function __construct(string $message = 'An active Snitch platform subscription is required.')
    {
        parent::__construct($message);
    }
}
