<?php

namespace App\Support\Addons;

use App\Models\SystemAddon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AddonDiscovery
{
    public function __construct(
        private readonly AddonManifestValidator $validator,
        private readonly AddonEventLogger $events,
    ) {}

    /**
     * @return array{
     *     manifests: array<int, array{path: string, relative_path: string, type: string, manifest: array<string, mixed>, checksum: string}>,
     *     invalid: array<int, array{path: string, relative_path: string, type: string, errors: array<int, string>}>,
     *     duplicates: array<int, array{code: string, paths: array<int, string>}>
     * }
     */
    public function scan(): array
    {
        $valid = [];
        $invalid = [];

        foreach ($this->manifestCandidates() as $candidate) {
            $validation = $this->validator->validateFile($candidate['path'], $candidate['type']);

            if (! $validation['valid'] || $validation['manifest'] === null) {
                $invalid[] = [
                    'path' => $candidate['path'],
                    'relative_path' => $candidate['relative_path'],
                    'type' => $candidate['type'],
                    'errors' => $validation['errors'],
                ];

                continue;
            }

            $valid[] = [
                'path' => $candidate['path'],
                'relative_path' => $candidate['relative_path'],
                'type' => $candidate['type'],
                'manifest' => $validation['manifest'],
                'checksum' => hash_file('sha256', $candidate['path']) ?: null,
            ];
        }

        [$manifests, $duplicates] = $this->splitDuplicates($valid);

        return [
            'manifests' => $manifests,
            'invalid' => $invalid,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @return array{discovered: int, invalid: int, duplicates: int}
     */
    public function sync(): array
    {
        $scan = $this->scan();

        if (! Schema::hasTable('system_addons')) {
            return [
                'discovered' => count($scan['manifests']),
                'invalid' => count($scan['invalid']),
                'duplicates' => count($scan['duplicates']),
            ];
        }

        foreach ($scan['manifests'] as $entry) {
            $this->persist($entry);
        }

        foreach ($scan['invalid'] as $invalid) {
            $this->events->error(null, 'invalid_manifest', 'Invalid addon manifest found.', [
                'path' => $invalid['relative_path'],
                'type' => $invalid['type'],
                'errors' => $invalid['errors'],
            ]);
        }

        foreach ($scan['duplicates'] as $duplicate) {
            $this->events->error($duplicate['code'], 'duplicate_manifest_code', 'Duplicate addon code found.', [
                'paths' => $duplicate['paths'],
            ]);
        }

        return [
            'discovered' => count($scan['manifests']),
            'invalid' => count($scan['invalid']),
            'duplicates' => count($scan['duplicates']),
        ];
    }

    public function syncManifest(string $path, string $type): ?SystemAddon
    {
        $validation = $this->validator->validateFile($path, $type);
        if (! $validation['valid'] || ! is_array($validation['manifest'])) {
            return null;
        }

        return $this->persist([
            'path' => $path,
            'relative_path' => $this->relativePath($path),
            'type' => $type,
            'manifest' => $validation['manifest'],
            'checksum' => hash_file('sha256', $path) ?: null,
        ]);
    }

    private function persist(array $entry): SystemAddon
    {
        $manifest = $entry['manifest'];
        $addon = SystemAddon::query()->firstOrNew(['code' => $manifest['code']]);
        $previousStatus = $addon->exists ? $addon->status : null;
        $metadata = $addon->metadata ?? [];
        $metadata['manifest'] = $manifest;
        $metadata['discovered_at'] = now()->toIso8601String();
        $addon->fill([
            'type' => $manifest['type'], 'name' => $manifest['name'], 'description' => $manifest['description'] ?? null,
            'vendor' => $manifest['vendor'] ?? null, 'version' => $manifest['version'],
            'source' => $addon->source ?: SystemAddon::SOURCE_LOCAL,
            'status' => $addon->exists && in_array($addon->status, [SystemAddon::STATUS_INSTALLED, SystemAddon::STATUS_ENABLED, SystemAddon::STATUS_DISABLED, SystemAddon::STATUS_FAILED], true) ? $addon->status : SystemAddon::STATUS_DISCOVERED,
            'manifest_path' => $entry['relative_path'], 'service_provider' => $manifest['service_provider'] ?? null,
            'checksum' => $entry['checksum'], 'metadata' => $metadata,
            'last_error' => $previousStatus === SystemAddon::STATUS_FAILED ? $addon->last_error : null,
        ])->save();
        $this->events->info($addon->code, 'discovered', 'Addon manifest discovered.', ['path' => $entry['relative_path'], 'type' => $addon->type]);

        return $addon;
    }

    /**
     * @return array<int, array{path: string, relative_path: string, type: string}>
     */
    private function manifestCandidates(): array
    {
        $patterns = [
            SystemAddon::TYPE_MODULE => base_path('modules/*/*/module.json'),
            SystemAddon::TYPE_EXTENSION => base_path('extensions/*/*/extension.json'),
        ];

        $candidates = [];

        foreach ($patterns as $type => $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                $candidates[] = [
                    'path' => $path,
                    'relative_path' => $this->relativePath($path),
                    'type' => $type,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param  array<int, array{path: string, relative_path: string, type: string, manifest: array<string, mixed>, checksum: string|null}>  $valid
     * @return array{
     *     0: array<int, array{path: string, relative_path: string, type: string, manifest: array<string, mixed>, checksum: string|null}>,
     *     1: array<int, array{code: string, paths: array<int, string>}>
     * }
     */
    private function splitDuplicates(array $valid): array
    {
        $groups = Collection::make($valid)->groupBy(fn (array $entry): string => (string) $entry['manifest']['code']);
        $duplicates = [];

        foreach ($groups as $code => $entries) {
            if ($entries->count() <= 1) {
                continue;
            }

            $duplicates[] = [
                'code' => (string) $code,
                'paths' => $entries->pluck('relative_path')->values()->all(),
            ];
        }

        $unique = $groups
            ->filter(fn (Collection $entries): bool => $entries->count() === 1)
            ->map(fn (Collection $entries): array => $entries->first())
            ->values()
            ->all();

        return [$unique, $duplicates];
    }

    private function relativePath(string $path): string
    {
        return Str::of($path)
            ->replace('\\', '/')
            ->after(Str::of(base_path())->replace('\\', '/').'/')
            ->toString();
    }
}
