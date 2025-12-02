<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    private int $availableStock;
    private int $reservedStock;
    private string $version;

    public function __construct(int $availableStock, int $reservedStock, string $version)
    {
        parent::__construct('Insufficient stock');
        $this->availableStock = $availableStock;
        $this->reservedStock = $reservedStock;
        $this->version = $version;
    }

    public function getAvailableStock(): int { return $this->availableStock; }
    public function getReservedStock(): int { return $this->reservedStock; }
    public function getVersion(): string { return $this->version; }
}

class RedisUnavailableException extends Exception {}
class HoldNotFoundException extends Exception {}
class InvalidHoldException extends Exception {}
class HoldNotExpiredException extends Exception
{
    private ?string $expiresAt;
    private int $secondsRemaining;

    public function __construct(?string $expiresAt, int $secondsRemaining)
    {
        parent::__construct('Hold not yet expired');
        $this->expiresAt = $expiresAt;
        $this->secondsRemaining = $secondsRemaining;
    }

    public function getExpiresAt(): ?string { return $this->expiresAt; }
    public function getSecondsRemaining(): int { return $this->secondsRemaining; }
}

class ConcurrentModificationException extends Exception
{
    public function __construct()
    {
        parent::__construct('Concurrent modification detected');
    }
}