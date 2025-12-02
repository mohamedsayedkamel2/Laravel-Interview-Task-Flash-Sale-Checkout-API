<?php

namespace App\Exceptions;

use Exception;

class HoldNotExpiredException extends Exception
{
    private ?string $expiresAt;
    private int $secondsRemaining;
    
    public function __construct(?string $expiresAt, int $secondsRemaining, $message = "", $code = 0, Exception $previous = null)
    {
        $this->expiresAt = $expiresAt;
        $this->secondsRemaining = $secondsRemaining;
        
        $message = $message ?: "Hold not yet expired. Expires at: {$expiresAt}, Seconds remaining: {$secondsRemaining}";
        
        parent::__construct($message, $code, $previous);
    }
    
    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }
    
    public function getSecondsRemaining(): int
    {
        return $this->secondsRemaining;
    }
}