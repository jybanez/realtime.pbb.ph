<?php

namespace App\Realtime\Auth;

use RuntimeException;

class RealtimeTokenValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message
    ) {
        parent::__construct($message);
    }
}
