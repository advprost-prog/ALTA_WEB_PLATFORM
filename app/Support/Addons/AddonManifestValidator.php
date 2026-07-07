<?php

namespace App\Support\Addons;

use App\Models\SystemAddon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use JsonException;

class AddonManifestValidator
{
    public const CODE_PATTERN = '/^[a-z0-9]+([._-][a-z0-9]+)*$/';

    /**
     * @return array{valid: bool, manifest: array<string, mixed>|null, errors: array<int, string>}
     */
    public function validateFile(string $path, string $expectedType): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [
                'valid' => false,
                'manifest' => null,
                'errors' => ['Manifest file is missing or not readable.'],
            ];
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [
                'valid' => false,
                'manifest' => null,
                'errors' => ['Manifest JSON is invalid: '.$exception->getMessage()],
            ];
        }

        if (! is_array($manifest)) {
            return [
                'valid' => false,
                'manifest' => null,
                'errors' => ['Manifest root must be a JSON object.'],
            ];
        }

        return $this->validate($manifest, $expectedType);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{valid: bool, manifest: array<string, mixed>, errors: array<int, string>}
     */
    public function validate(array $manifest, string $expectedType): array
    {
        $manifest = $this->normalize($manifest, $expectedType);
        $errors = [];

        foreach (['code', 'type', 'name', 'version', 'vendor', 'enabled_by_default', 'dependencies', 'settings_schema', 'compatibility'] as $field) {
            if (! array_key_exists($field, $manifest)) {
                $errors[] = "Missing required field [{$field}].";
            }
        }

        if (! in_array($expectedType, array_keys(SystemAddon::TYPES), true)) {
            $errors[] = 'Expected type is not supported.';
        }

        if (($manifest['type'] ?? null) !== $expectedType) {
            $errors[] = "Manifest type must be [{$expectedType}].";
        }

        if (! is_string($manifest['code'] ?? null) || preg_match(self::CODE_PATTERN, (string) $manifest['code']) !== 1) {
            $errors[] = 'Manifest code must be a stable slug-like identifier, for example alta.suppliers.';
        }

        foreach (['name', 'version', 'vendor'] as $field) {
            if (! is_string($manifest[$field] ?? null) || trim((string) $manifest[$field]) === '') {
                $errors[] = "Manifest [{$field}] must be a non-empty string.";
            }
        }

        if (! is_bool($manifest['enabled_by_default'] ?? null)) {
            $errors[] = 'Manifest [enabled_by_default] must be boolean.';
        }

        foreach (['dependencies', 'settings_schema'] as $field) {
            if (! is_array($manifest[$field] ?? null)) {
                $errors[] = "Manifest [{$field}] must be an array.";
            }
        }

        if (! is_array($manifest['compatibility'] ?? null)) {
            $errors[] = 'Manifest [compatibility] must be an object.';
        }

        if ($expectedType === SystemAddon::TYPE_MODULE) {
            foreach (['permissions', 'menu', 'migrations', 'seeders', 'routes'] as $field) {
                if (! is_array($manifest[$field] ?? null)) {
                    $errors[] = "Module manifest [{$field}] must be an array.";
                }
            }
        }

        if ($expectedType === SystemAddon::TYPE_EXTENSION && ! is_array($manifest['hooks'] ?? null)) {
            $errors[] = 'Extension manifest [hooks] must be an array.';
        }

        if (isset($manifest['service_provider']) && $manifest['service_provider'] !== null && ! is_string($manifest['service_provider'])) {
            $errors[] = 'Manifest [service_provider] must be a class string or null.';
        }

        foreach ($manifest['dependencies'] ?? [] as $dependency) {
            $dependencyCode = is_array($dependency) ? ($dependency['code'] ?? null) : $dependency;

            if (! is_string($dependencyCode) || preg_match(self::CODE_PATTERN, $dependencyCode) !== 1) {
                $errors[] = 'Each dependency must be an addon code or an object with a valid code.';
                break;
            }
        }

        foreach ($manifest['hooks'] ?? [] as $hook) {
            if (! is_array($hook) || ! is_string($hook['name'] ?? null) || ! is_string($hook['handler'] ?? null)) {
                $errors[] = 'Each hook must have string [name] and [handler] fields.';
                break;
            }
        }

        return [
            'valid' => $errors === [],
            'manifest' => $manifest,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function normalize(array $manifest, string $expectedType): array
    {
        $manifest['type'] ??= $expectedType;
        $manifest['description'] ??= null;
        $manifest['author'] ??= null;
        $manifest['enabled_by_default'] ??= false;
        $manifest['service_provider'] ??= null;
        $manifest['dependencies'] = $this->arrayValue($manifest, 'dependencies');
        $manifest['settings_schema'] = $this->arrayValue($manifest, 'settings_schema');
        $manifest['compatibility'] = is_array($manifest['compatibility'] ?? null) ? $manifest['compatibility'] : [];
        $manifest['compatibility'] = array_merge([
            'app_min_version' => null,
            'app_max_version' => null,
            'laravel_version' => null,
            'php_version' => null,
        ], $manifest['compatibility']);

        if ($expectedType === SystemAddon::TYPE_MODULE) {
            $manifest['permissions'] = $this->arrayValue($manifest, 'permissions');
            $manifest['menu'] = $this->arrayValue($manifest, 'menu');
            $manifest['migrations'] = $this->arrayValue($manifest, 'migrations');
            $manifest['seeders'] = $this->arrayValue($manifest, 'seeders');
            $manifest['routes'] = $this->arrayValue($manifest, 'routes');
        }

        if ($expectedType === SystemAddon::TYPE_EXTENSION) {
            $manifest['hooks'] = $this->arrayValue($manifest, 'hooks');
        }

        $manifest['code'] = Str::lower(trim((string) ($manifest['code'] ?? '')));

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int|string, mixed>
     */
    private function arrayValue(array $manifest, string $key): array
    {
        $value = Arr::get($manifest, $key, []);

        return is_array($value) ? $value : [];
    }
}
