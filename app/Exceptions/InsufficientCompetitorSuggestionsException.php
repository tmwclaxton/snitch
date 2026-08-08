<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class InsufficientCompetitorSuggestionsException extends RuntimeException
{
    /**
     * @param  list<array{platform: string, handle: string, url: string, display_name: string, avatar: string|null, source: string|null}>  $suggestions
     */
    public function __construct(
        public array $suggestions,
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
