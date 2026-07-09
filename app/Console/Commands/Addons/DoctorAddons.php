<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\AddonHealthCheck;
use App\Support\Addons\Marketplace\MarketplaceManager;
use Illuminate\Console\Command;

class DoctorAddons extends Command
{
    protected $signature = 'addons:doctor {--json : Output machine-readable JSON}';

    protected $description = 'Report addon manifest, dependency, compatibility, and lifecycle diagnostics.';

    public function handle(AddonHealthCheck $healthCheck, MarketplaceManager $marketplace): int
    {
        $diagnostics = $healthCheck->diagnostics();
        $issues = $diagnostics['issues'];
        $warnings = $diagnostics['warnings'];

        $resolved = $marketplace->resolve();

        foreach ($resolved['diagnostics'] as $diagnostic) {
            if ($diagnostic === 'Registry is disabled.') {
                continue;
            }

            $issues[] = $this->diagnostic('addon_catalog_diagnostic', 'Marketplace catalog diagnostic.', [$diagnostic]);
        }

        foreach ($resolved['rows'] as $row) {
            $code = $row['item']->code;
            $source = $row['source'] ?? 'local';

            if ($source === 'remote' || $source === 'local_remote') {
                $remoteVersion = $row['remote_version'] ?? null;
                $installedVersion = $row['installed_version'] ?? null;

                if ($remoteVersion !== null && $installedVersion !== null && $remoteVersion !== $installedVersion) {
                    $warnings[] = $this->diagnostic('addon_remote_version_mismatch', 'Remote registry version differs from installed/local version.', [
                        $code.' remote '.$remoteVersion.' != installed '.$installedVersion,
                    ]);
                }
            }

            if ($row['compatibility_status'] === 'incompatible') {
                $issues[] = $this->diagnostic('addon_incompatible', 'Addon is incompatible with the current platform version.', [
                    $code.' requires '.$row['platform_constraint'],
                ]);
            }

            if ($row['update_status'] === 'installed_newer') {
                $warnings[] = $this->diagnostic('addon_installed_newer', 'Installed version is newer than the catalog version.', [
                    $code.' installed '.$row['installed_version'].' > catalog '.$row['available_version'],
                ]);
            }

            if ($row['update_status'] === 'update_available') {
                $warnings[] = $this->diagnostic('addon_update_available', 'Addon update is available.', [
                    $code.' installed '.$row['installed_version'].' < catalog '.$row['available_version'],
                ]);
            }

            foreach ($row['dependency_issues'] as $issue) {
                if (str_contains($issue, 'не відповідає обмеженню')) {
                    $issues[] = $this->diagnostic('addon_dependency_version_mismatch', 'Addon dependency version mismatch.', [
                        $code.': '.$issue,
                    ]);
                } elseif (str_contains($issue, 'не встановлено')) {
                    $level = $row['status'] === 'enabled' ? 'issues' : 'warnings';
                    ${$level}[] = $this->diagnostic('addon_dependency_missing', 'Addon dependency is missing or not installed.', [
                        $code.': '.$issue,
                    ]);
                } elseif (str_contains($issue, 'вимкнено')) {
                    $issues[] = $this->diagnostic('addon_dependency_disabled', 'Addon dependency is disabled.', [
                        $code.': '.$issue,
                    ]);
                } elseif (str_contains($issue, 'несумісна')) {
                    $issues[] = $this->diagnostic('addon_dependency_incompatible', 'Addon dependency is incompatible.', [
                        $code.': '.$issue,
                    ]);
                } elseif (str_contains($issue, 'відсутній маніфест')) {
                    $warnings[] = $this->diagnostic('addon_dependency_missing_files', 'Addon dependency has missing manifest.', [
                        $code.': '.$issue,
                    ]);
                } elseif (str_contains($issue, 'некоректна')) {
                    $warnings[] = $this->diagnostic('addon_dependency_invalid', 'Addon dependency is invalid.', [
                        $code.': '.$issue,
                    ]);
                }
            }

            if (isset($row['blocked_reasons']) && $row['blocked_reasons'] !== []) {
                $warnings[] = $this->diagnostic('addon_dependency_blocked', 'Addon actions are blocked by dependencies.', [
                    $code.': '.implode('; ', $row['blocked_reasons']),
                ]);
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(['issues' => $issues, 'warnings' => $warnings], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $issues === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Addon doctor');

        if ($issues === [] && $warnings === []) {
            $this->info('No addon issues found.');

            return self::SUCCESS;
        }

        if ($issues !== []) {
            $this->warn('Issues:');
            $this->renderDiagnostics($issues);
        }

        if ($warnings !== []) {
            $this->warn('Warnings:');
            $this->renderDiagnostics($warnings);
        }

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, array{code: string, message: string, count: int, examples: array<int, string>}>  $diagnostics
     */
    private function renderDiagnostics(array $diagnostics): void
    {
        foreach ($diagnostics as $diagnostic) {
            $this->line('- '.$diagnostic['code'].': '.$diagnostic['message']);

            foreach ($diagnostic['examples'] as $example) {
                $this->line('  example: '.$example);
            }
        }
    }

    /**
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
