<?php

namespace App\Exceptions;

use Exception;

class HoldAlreadyUsedException extends Exception
{
    public function __construct(string $message = 'Hold already used')
    {
        parent::__construct($message);
    }
}