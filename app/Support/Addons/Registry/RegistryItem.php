<?php

namespace App\Support\Addons\Registry;

class RegistryItem
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $code,
        public readonly string $type,
        public readonly string $vendor,
        public readonly string $name,
        public readonly string $description,
        public readonly string $version,
        public readonly ?string $category,
        public readonly array $tags,
        public readonly ?string $platformConstraint,
        public readonly array $dependencies,
        public readonly bool $isFeatured,
        public readonly ?string $homepageUrl,
        public readonly ?string $documentationUrl,
        public readonly ?string $artifact,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $code = (string) ($data['code'] ?? '');
        $version = (string) ($data['version'] ?? '');

        return new self(
            code: $code,
            type: (string) ($data['type'] ?? 'extension'),
            vendor: (string) ($data['vendor'] ?? ''),
            name: (string) ($data['name'] ?? $code),
            description: (string) ($data['description'] ?? ''),
            version: $version,
            category: isset($data['category']) ? (string) $data['category'] : null,
            tags: is_array($data['tags'] ?? null) ? $data['tags'] : [],
            platformConstraint: isset($data['requires_platform']) && is_string($data['requires_platform']) && $data['requires_platform'] !== '' ? $data['requires_platform'] : null,
            dependencies: self::normalizeDependencies($data['dependencies'] ?? []),
            isFeatured: (bool) ($data['is_featured'] ?? false),
            homepageUrl: isset($data['homepage_url']) && is_string($data['homepage_url']) && $data['homepage_url'] !== '' ? $data['homepage_url'] : null,
            documentationUrl: isset($data['documentation_url']) && is_string($data['documentation_url']) && $data['documentation_url'] !== '' ? $data['documentation_url'] : null,
            artifact: isset($data['artifact']) && is_string($data['artifact']) && $data['artifact'] !== '' ? $data['artifact'] : null,
            raw: $data,
        );
    }

    /**
     * @param  array<int, array<string, mixed>|string>  $dependencies
     * @return array<int, array{code: string, constraint: string|null}>
     */
    private static function normalizeDependencies(array $dependencies): array
    {
        $normalized = [];

        foreach ($dependencies as $dependency) {
            if (is_array($dependency)) {
                $code = (string) ($dependency['code'] ?? '');
                $constraint = isset($dependency['constraint']) && is_string($dependency['constraint']) && $dependency['constraint'] !== '' && $dependency['constraint'] !== '*' ? $dependency['constraint'] : null;
            } else {
                $code = (string) $dependency;
                $constraint = null;
            }

            if ($code === '') {
                continue;
            }

            $normalized[] = [
                'code' => $code,
                'constraint' => $constraint,
            ];
        }

        return $normalized;
    }
}
