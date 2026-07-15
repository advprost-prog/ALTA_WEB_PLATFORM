<?php

namespace App\Support\Addons\Providers;

use RuntimeException;

final class AddonProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $diagnosticCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
