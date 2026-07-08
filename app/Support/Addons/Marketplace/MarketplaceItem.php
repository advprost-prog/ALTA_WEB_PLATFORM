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
        public readonly array $tags,
        public readonly bool $isFeatured,
        public readonly int $sortOrder,
        public readonly bool $valid = true,
        public readonly array $errors = [],
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

        $dependencies = [];
        $rawDependencies = is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [];
        foreach ($rawDependencies as $dependency) {
            $dependencyCode = is_array($dependency) ? (string) ($dependency['code'] ?? '') : (string) $dependency;
            if ($dependencyCode !== '' && preg_match(AddonManifestValidator::CODE_PATTERN, $dependencyCode) === 1) {
                $dependencies[] = $dependencyCode;
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
            tags: $tags,
            isFeatured: is_bool($data['is_featured'] ?? null) ? $data['is_featured'] : false,
            sortOrder: is_int($data['sort_order'] ?? null) ? $data['sort_order'] : 999,
            valid: $errors === [],
            errors: $errors,
        );
    }

    public function isValid(): bool
    {
        return $this->valid;
    }
}
