<?php

namespace App\Exceptions;

use RuntimeException;

class DomainProvisioningException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $processOutput = '',
    ) {
        parent::__construct($message);
    }

    public function processOutput(): string
    {
        return $this->processOutput;
    }
}
