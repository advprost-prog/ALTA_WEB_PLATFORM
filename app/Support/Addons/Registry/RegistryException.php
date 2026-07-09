<?php

namespace App\Support\Addons\Registry;

use RuntimeException;

class RegistryException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('Registry is disabled.');
    }

    public static function urlMissing(): self
    {
        return new self('Registry URL is not configured.');
    }

    public static function hostNotAllowed(string $host): self
    {
        return new self("Registry host [{$host}] is not allowed.");
    }

    public static function requestFailed(string $message): self
    {
        return new self('Registry request failed: '.$message);
    }

    public static function invalidJson(string $message): self
    {
        return new self('Invalid registry JSON: '.$message);
    }
}
