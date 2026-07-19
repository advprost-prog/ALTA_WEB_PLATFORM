<?php

namespace App\Support\Addons\BackupRestore;

use RuntimeException;

final class HostRestoreException extends RuntimeException
{
    public function __construct(public readonly string $failureCode, string $message)
    {
        parent::__construct($message);
    }
}
