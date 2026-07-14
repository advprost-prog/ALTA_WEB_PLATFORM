<?php

namespace App\Support\Addons\Registry;

use App\Models\SystemAddon;
use App\Support\Addons\AddonManifestValidator;
use Illuminate\Support\Str;
use RuntimeException;

final class AddonLivePathResolver
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array{type: string, vendor: string, code_segment: string, root: string, live_path: string}
     */
    public function resolve(array $manifest): array
    {
        $type = (string) ($manifest['type'] ?? '');

        if (! in_array($type, [SystemAddon::TYPE_MODULE, SystemAddon::TYPE_EXTENSION], true)) {
            throw new RuntimeException('Unsupported addon type.');
        }

        $code = (string) ($manifest['code'] ?? '');
        $vendorSource = trim((string) ($manifest['vendor'] ?? ''));
        if ($vendorSource === '') {
            $vendorSource = (string) Str::before($code, '.');
        }

        $vendor = $this->normalizeSegment($vendorSource, 'vendor');
        $codeSegment = $this->normalizeCodeSegment($code);
        $root = $this->rootForType($type);
        $livePath = $root.'/'.$vendor.'/'.$codeSegment;

        return [
            'type' => $type,
            'vendor' => $vendor,
            'code_segment' => $codeSegment,
            'root' => $root,
            'live_path' => $livePath,
        ];
    }

    private function rootForType(string $type): string
    {
        $configKey = $type === SystemAddon::TYPE_MODULE ? 'modules_path' : 'extensions_path';
        $default = $type === SystemAddon::TYPE_MODULE ? base_path('modules') : base_path('extensions');
        $root = (string) config('addons-registry.live_roots.'.$configKey, $default);

        return rtrim(str_replace('\\', '/', $root), '/');
    }

    private function normalizeCodeSegment(string $code): string
    {
        if ($code === '' || preg_match('/[\\/\0]/', $code) || str_contains($code, '..')) {
            throw new RuntimeException('Addon code is unsafe.');
        }

        if (preg_match(AddonManifestValidator::CODE_PATTERN, Str::lower($code)) !== 1) {
            throw new RuntimeException('Addon code is not a valid manifest code.');
        }

        $parts = explode('.', Str::lower($code));
        $segment = $this->normalizeSegment((string) end($parts), 'code');

        return $segment;
    }

    private function normalizeSegment(string $segment, string $field): string
    {
        $segment = trim($segment);

        if ($segment === '' || preg_match('/[\\/\0]/', $segment) || str_contains($segment, '..')) {
            throw new RuntimeException("Addon {$field} segment is unsafe.");
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $segment)) {
            throw new RuntimeException("Addon {$field} segment is invalid.");
        }

        $normalized = Str::studly($segment);

        if ($normalized === '') {
            throw new RuntimeException("Addon {$field} segment normalized to empty string.");
        }

        return $normalized;
    }
}
