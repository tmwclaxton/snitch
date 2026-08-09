<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class InsufficientInfluencerSuggestionsException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $suggestions
     */
    public function __construct(
        public array $suggestions,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
