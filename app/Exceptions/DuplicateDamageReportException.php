<?php

namespace App\Exceptions;

use RuntimeException;

class DuplicateDamageReportException extends RuntimeException
{
    public function __construct(private readonly array $duplicate)
    {
        parent::__construct('A similar active damage report already exists for this item in the selected room.');
    }

    public function getDuplicate(): array
    {
        return $this->duplicate;
    }
}
