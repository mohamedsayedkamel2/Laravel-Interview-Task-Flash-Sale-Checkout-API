<?php

namespace App\Exceptions;

use Exception;

class ConcurrentModificationException extends Exception
{
    public function __construct(string $message = 'Concurrent modification detected')
    {
        parent::__construct($message);
    }
}