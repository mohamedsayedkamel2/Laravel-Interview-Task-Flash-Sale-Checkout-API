<?php

namespace App\Exceptions;

use Exception;

class HoldExpiredException extends Exception
{
    private ?string $expiresAt;

    public function __construct(?string $expiresAt, string $message = 'Hold expired')
    {
        parent::__construct($message);
        $this->expiresAt = $expiresAt;
    }

    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }
}