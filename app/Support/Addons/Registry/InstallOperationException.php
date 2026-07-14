<?php

namespace App\Support\Addons\Registry;

use RuntimeException;

final class InstallOperationException extends RuntimeException
{
    public function __construct(public readonly string $failureCode, string $message)
    {
        parent::__construct($message);
    }
}
