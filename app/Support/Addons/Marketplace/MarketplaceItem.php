<?php

namespace App\Support\Addons\Marketplace;

use App\Models\SystemAddon;
use App\Support\Addons\AddonManifestValidator;

/**
 * Immutable value object describing a single local marketplace catalog item.
 *
 * Catalog entries are parsed defensively: an invalid entry is still wrapped in
 * a MarketplaceItem (with valid=false and collected errors) so the Marketplace
 * UI never crashes because of a broken config entry.
 */
final class MarketplaceItem
{
    /**
     * @param  array<int, string>  $dependencies
     * @param  array<string, string|null>  $dependencyConstraints  code => constraint (null = any)
     * @param  array<int, string>  $tags
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly string $code,
        public readonly string $type,
        public readonly string $vendor,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $category,
        public readonly ?string $icon,
        public readonly ?string $path,
        public readonly ?string $platformVersion,
        public readonly array $dependencies,
        public readonly ?string $platformConstraint,
        public readonly array $dependencyConstraints,
        public readonly array $tags,
        public readonly bool $isFeatured,
        public readonly int $sortOrder,
        public readonly bool $valid = true,
        public readonly array $errors = [],
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        foreach (['code', 'type', 'name', 'version', 'vendor'] as $field) {
            if (! is_string($data[$field] ?? null) || trim((string) $data[$field]) === '') {
                $errors[] = "Missing or empty required field [{$field}].";
            }
        }

        $type = (string) ($data['type'] ?? '');
        if (! in_array($type, [SystemAddon::TYPE_MODULE, SystemAddon::TYPE_EXTENSION], true)) {
            $errors[] = 'Field [type] must be "module" or "extension".';
        }

        $code = (string) ($data['code'] ?? '');
        if ($code !== '' && preg_match(AddonManifestValidator::CODE_PATTERN, $code) !== 1) {
            $errors[] = 'Field [code] must be a stable slug-like identifier, for example core.products.';
        }

        if (isset($data['dependencies']) && ! is_array($data['dependencies'])) {
            $errors[] = 'Field [dependencies] must be an array of addon codes.';
        }

        if (isset($data['tags']) && ! is_array($data['tags'])) {
            $errors[] = 'Field [tags] must be an array.';
        }

        if (isset($data['is_featured']) && ! is_bool($data['is_featured'])) {
            $errors[] = 'Field [is_featured] must be boolean.';
        }

        if (isset($data['sort_order']) && ! is_int($data['sort_order'])) {
            $errors[] = 'Field [sort_order] must be an integer.';
        }

        $version = (string) ($data['version'] ?? '');
        if ($version !== '' && ! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            $errors[] = 'Field [version] must be a semantic version like 1.0.0.';
        }

        $platformConstraint = self::resolvePlatformConstraint($data);
        if ($platformConstraint !== null && ! self::isValidConstraint($platformConstraint)) {
            $errors[] = "Field [platform_version] must be a version constraint like >=1.0.0 (got [{$platformConstraint}]).";
        }

        $dependencies = [];
        $dependencyConstraints = [];
        $rawDependencies = is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [];
        foreach ($rawDependencies as $dependency) {
            $dependencyCode = is_array($dependency) ? (string) ($dependency['code'] ?? '') : (string) $dependency;
            $dependencyConstraint = is_array($dependency) ? (is_string($dependency['constraint'] ?? null) ? $dependency['constraint'] : null) : null;

            if ($dependencyCode === '' || preg_match(AddonManifestValidator::CODE_PATTERN, $dependencyCode) !== 1) {
                continue;
            }

            $dependencies[] = $dependencyCode;

            if ($dependencyConstraint !== null && $dependencyConstraint !== '' && $dependencyConstraint !== '*') {
                $dependencyConstraints[$dependencyCode] = $dependencyConstraint;
            }
        }

        $tags = [];
        $rawTags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
        foreach ($rawTags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $tags[] = $tag;
            }
        }

        return new self(
            code: $code,
            type: $type,
            vendor: (string) ($data['vendor'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            version: (string) ($data['version'] ?? ''),
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            category: is_string($data['category'] ?? null) ? $data['category'] : '',
            icon: is_string($data['icon'] ?? null) ? $data['icon'] : null,
            path: is_string($data['path'] ?? null) && $data['path'] !== '' ? $data['path'] : null,
            platformVersion: is_string($data['platform_version'] ?? null) && $data['platform_version'] !== '' ? $data['platform_version'] : null,
            dependencies: $dependencies,
            platformConstraint: $platformConstraint,
            dependencyConstraints: $dependencyConstraints,
            tags: $tags,
            isFeatured: is_bool($data['is_featured'] ?? null) ? $data['is_featured'] : false,
            sortOrder: is_int($data['sort_order'] ?? null) ? $data['sort_order'] : 999,
            valid: $errors === [],
            errors: $errors,
            raw: $data,
        );
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getPlatformConstraint(): ?string
    {
        return $this->platformConstraint;
    }

    /**
     * Normalized dependency list with optional constraints.
     *
     * @return array<int, array{code: string, constraint: string|null}>
     */
    public function getDependencies(): array
    {
        $dependencies = [];

        foreach ($this->dependencyConstraints as $code => $constraint) {
            $dependencies[] = ['code' => $code, 'constraint' => $constraint];
        }

        foreach ($this->dependencies as $code) {
            if (! isset($this->dependencyConstraints[$code])) {
                $dependencies[] = ['code' => $code, 'constraint' => null];
            }
        }

        return $dependencies;
    }

    /**
     * @return array<int, string>
     */
    public function getDependencyCodes(): array
    {
        return array_values(array_unique([...array_keys($this->dependencyConstraints), ...$this->dependencies]));
    }

    /**
     * @return array<string, string|null>
     */
    public function getDependencyConstraints(): array
    {
        return $this->dependencyConstraints;
    }

    private static function resolvePlatformConstraint(array $data): ?string
    {
        foreach (['platform_version', 'requires_platform', 'platform_constraint'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function isValidConstraint(string $constraint): bool
    {
        if ($constraint === '*') {
            return true;
        }

        return preg_match('/^(\^|>=|<=|>|<|=)?\s*(\d+(\.\d+){0,2}|\*)$/', trim($constraint)) === 1;
    }
}
