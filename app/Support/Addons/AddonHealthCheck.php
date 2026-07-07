<?php

namespace App\Support\Addons;

use App\Models\SystemAddon;
use Illuminate\Support\Facades\Schema;

class AddonHealthCheck
{
    public function __construct(
        private readonly AddonDiscovery $discovery,
        private readonly AddonRegistry $registry,
        private readonly AddonLifecycle $lifecycle,
    ) {}

    /**
     * @return array{issues: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function diagnostics(): array
    {
        $scan = $this->discovery->scan();
        $issues = [];
        $warnings = [];

        foreach ($scan['invalid'] as $invalid) {
            $issues[] = $this->diagnostic('addon_invalid_manifest', 'Invalid addon manifest.', [
                $invalid['relative_path'].': '.implode('; ', $invalid['errors']),
            ]);
        }

        foreach ($scan['duplicates'] as $duplicate) {
            $issues[] = $this->diagnostic('addon_duplicate_code', 'Duplicate addon code found.', [
                $duplicate['code'].' in '.implode(', ', $duplicate['paths']),
            ]);
        }

        if (! Schema::hasTable('system_addons')) {
            $warnings[] = $this->diagnostic('addon_tables_missing', 'Addon database tables are not migrated yet.');

            return ['issues' => $issues, 'warnings' => $warnings];
        }

        foreach ($this->registry->all() as $addon) {
            if ($addon->status === SystemAddon::STATUS_FAILED) {
                $issues[] = $this->diagnostic('addon_failed_status', 'Addon is in failed status.', [
                    $addon->code.($addon->last_error ? ': '.$addon->last_error : ''),
                ]);
            }

            if ($addon->is_enabled && $addon->manifest_path && ! is_file(base_path($addon->manifest_path))) {
                $issues[] = $this->diagnostic('addon_enabled_missing_manifest', 'Enabled addon manifest file is missing.', [
                    $addon->code.' -> '.$addon->manifest_path,
                ]);
            }

            if ($addon->is_enabled && ! $addon->manifest_path) {
                $issues[] = $this->diagnostic('addon_enabled_without_manifest', 'Enabled addon has no manifest path.', [
                    $addon->code,
                ]);
            }

            if ($addon->is_enabled && $addon->service_provider && ! $this->lifecycle->serviceProviderIsAllowed($addon)) {
                $issues[] = $this->diagnostic('addon_service_provider_blocked', 'Enabled addon service provider is outside allowed namespace/path.', [
                    $addon->code.' -> '.$addon->service_provider,
                ]);
            }

            if ($addon->is_enabled && $addon->service_provider && $this->lifecycle->serviceProviderIsAllowed($addon) && ! $this->lifecycle->serviceProviderClassExists($addon)) {
                $issues[] = $this->diagnostic('addon_service_provider_missing', 'Enabled addon service provider class is missing.', [
                    $addon->code.' -> '.$addon->service_provider,
                ]);
            }

            foreach ($this->lifecycle->dependencyIssues($addon, requireEnabled: $addon->is_enabled) as $dependencyIssue) {
                $issues[] = $this->diagnostic('addon_dependency_issue', 'Addon dependency issue.', [
                    $addon->code.': '.$dependencyIssue,
                ]);
            }

            foreach ($this->lifecycle->compatibilityIssues($addon) as $compatibilityIssue) {
                $issues[] = $this->diagnostic('addon_compatibility_issue', 'Addon compatibility issue.', [
                    $addon->code.': '.$compatibilityIssue,
                ]);
            }
        }

        return [
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<int, string>  $examples
     * @return array{code: string, message: string, count: int, examples: array<int, string>}
     */
    private function diagnostic(string $code, string $message, array $examples = []): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'count' => count($examples),
            'examples' => $examples,
        ];
    }
}
