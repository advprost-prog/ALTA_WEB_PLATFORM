<?php

namespace App\Support\Addons\Registry;

use DateTimeImmutable;

final class RegistrySchemaValidator
{
    /** @return array{valid: bool, document: array<string, mixed>|null, diagnostics: list<string>} */
    public function validate(array $payload, array $config = []): array
    {
        $errors = [];
        $registry = $payload['registry'] ?? null;
        $items = $payload['items'] ?? null;

        if (! is_array($registry)) {
            $errors[] = 'Registry header must be an object.';
        } else {
            foreach (['name', 'version', 'application_version', 'build_version', 'schema_version', 'generated_at'] as $field) {
                if (! is_string($registry[$field] ?? null) || $registry[$field] === '') {
                    $errors[] = "Registry field [{$field}] must be a non-empty string.";
                }
            }
            if (($registry['schema_version'] ?? null) !== '1') {
                $errors[] = 'Unsupported Registry schema version.';
            }
            if (is_string($registry['generated_at'] ?? null) && ! $this->isDate($registry['generated_at'])) {
                $errors[] = 'Registry generated_at must be a valid ISO-8601 datetime.';
            }
        }
        if (! is_array($items) || ! array_is_list($items)) {
            $errors[] = 'Registry items must be an array.';
            $items = [];
        }

        $normalized = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $errors[] = "Registry item [{$index}] must be an object.";

                continue;
            }
            $before = count($errors);
            $normalizedItem = $this->validateItem($item, $index, $config, $errors);
            if (count($errors) === $before) {
                $normalized[] = $normalizedItem;
            }
        }

        if ($errors !== []) {
            return ['valid' => false, 'document' => null, 'diagnostics' => array_values(array_unique($errors))];
        }

        return ['valid' => true, 'document' => ['registry' => [
            'name' => $registry['name'], 'version' => $registry['version'],
            'application_version' => $registry['application_version'], 'build_version' => $registry['build_version'],
            'schema_version' => '1', 'generated_at' => $registry['generated_at'],
        ], 'items' => $normalized], 'diagnostics' => []];
    }

    private function validateItem(array $item, int $index, array $config, array &$errors): array
    {
        $prefix = "Registry item [{$index}]";
        foreach (['code', 'vendor', 'name', 'description', 'version'] as $field) {
            if (! is_string($item[$field] ?? null) || ($field !== 'description' && trim($item[$field]) === '')) {
                $errors[] = "{$prefix} field [{$field}] has an invalid type or value.";
            }
        }
        if (! in_array($item['type'] ?? null, ['module', 'extension'], true)) {
            $errors[] = "{$prefix} has an unsupported type.";
        }
        if (is_string($item['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $item['version']) !== 1) {
            $errors[] = "{$prefix} has an invalid version.";
        }
        foreach (['category', 'requires_platform'] as $field) {
            if (! is_null($item[$field] ?? null) && ! is_string($item[$field])) {
                $errors[] = "{$prefix} field [{$field}] must be string or null.";
            }
        }
        if (! is_bool($item['is_featured'] ?? null)) {
            $errors[] = "{$prefix} field [is_featured] must be boolean.";
        }
        if (! $this->stringList($item['tags'] ?? null)) {
            $errors[] = "{$prefix} tags must be an array of strings.";
        }
        foreach (['homepage_url', 'documentation_url'] as $field) {
            if (! $this->nullableUrl($item[$field] ?? null)) {
                $errors[] = "{$prefix} field [{$field}] must be a valid URL or null.";
            }
        }

        $dependencies = $item['dependencies'] ?? null;
        if (! is_array($dependencies) || ! array_is_list($dependencies)) {
            $errors[] = "{$prefix} dependencies must be an array.";
            $dependencies = [];
        }
        foreach ($dependencies as $dependency) {
            if (! is_array($dependency) || ! is_string($dependency['code'] ?? null) || trim($dependency['code']) === ''
                || (! is_null($dependency['constraint'] ?? null) && ! is_string($dependency['constraint']))
                || ! is_bool($dependency['required'] ?? null)) {
                $errors[] = "{$prefix} contains an invalid dependency.";
            }
        }
        $publisher = $item['publisher'] ?? null;
        if (! is_array($publisher) || ! is_string($publisher['public_id'] ?? null) || ! $this->isUuid($publisher['public_id']) || ! is_string($publisher['name'] ?? null) || trim($publisher['name']) === '') {
            $errors[] = "{$prefix} publisher is invalid.";
        }
        if (! is_string($item['published_at'] ?? null) || ! $this->isDate($item['published_at'])) {
            $errors[] = "{$prefix} published_at is invalid.";
        }

        $artifact = $item['artifact'] ?? null;
        if (! is_array($artifact)) {
            $errors[] = "{$prefix} artifact is invalid.";
            $artifact = [];
        }
        $url = $artifact['url'] ?? null;
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || (! app()->environment(['local', 'testing']) && parse_url($url, PHP_URL_SCHEME) !== 'https')) {
            $errors[] = "{$prefix} artifact URL is invalid.";
        }
        if (($artifact['type'] ?? null) !== 'zip') {
            $errors[] = "{$prefix} artifact type must be zip.";
        }
        if (! is_string($artifact['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $artifact['sha256']) !== 1) {
            $errors[] = "{$prefix} artifact SHA-256 is invalid.";
        }
        $max = (int) ($config['downloads']['max_size'] ?? PHP_INT_MAX);
        if (! is_int($artifact['size'] ?? null) || $artifact['size'] < 1 || $artifact['size'] > $max) {
            $errors[] = "{$prefix} artifact size is invalid.";
        }
        $signature = $artifact['signature'] ?? null;
        if (! is_array($signature) || ($signature['type'] ?? null) !== 'ed25519' || ! is_string($signature['value'] ?? null)
            || $signature['value'] === '' || base64_decode($signature['value'], true) === false
            || ! is_string($signature['key_id'] ?? null) || trim($signature['key_id']) === ''
            || ($signature['payload_version'] ?? null) !== 'raw-zip-v1') {
            $errors[] = "{$prefix} artifact signature is invalid.";
        }

        return $item;
    }

    private function stringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count(array_filter($value, 'is_string')) === count($value);
    }

    private function nullableUrl(mixed $value): bool
    {
        return $value === null || (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false);
    }

    private function isDate(string $value): bool
    {
        try {
            new DateTimeImmutable($value);

            return preg_match('/T/', $value) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) === 1;
    }
}
