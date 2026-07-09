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
        $info = [];

        $resolved = $marketplace->resolve();

        $downloadsConfig = config('addons-registry.downloads', []);
        $downloadsEnabled = (bool) ($downloadsConfig['enabled'] ?? false);
        $maxSize = (int) ($downloadsConfig['max_size'] ?? 20 * 1024 * 1024);

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

            $artifact = $row['artifact'] ?? null;
            $artifactStatus = $row['artifact_status'] ?? 'not_available';

            if ($artifact !== null) {
                if (! $downloadsEnabled) {
                    $info[] = $this->diagnostic('addon_artifact_downloads_disabled', 'Artifact downloads are disabled.', [
                        $code.': set ADDONS_REGISTRY_DOWNLOADS_ENABLED=true to allow quarantine downloads.',
                    ]);
                }

                $host = parse_url($artifact['url'] ?? '', PHP_URL_HOST) ?: '';
                $allowed = $this->isArtifactHostAllowed($host);

                if ($downloadsEnabled && $host !== '' && ! $allowed) {
                    $warnings[] = $this->diagnostic('addon_artifact_host_not_allowed', 'Artifact host is not in the allowed hosts list.', [
                        $code.' host ['.$host.'] is not allowed.',
                    ]);
                }

                if (empty($artifact['sha256'])) {
                    $warnings[] = $this->diagnostic('addon_artifact_missing_sha256', 'Artifact is missing a sha256 checksum.', [
                        $code.' artifact has no sha256 in registry metadata.',
                    ]);
                }

                if (isset($artifact['size']) && is_int($artifact['size']) && $artifact['size'] > $maxSize) {
                    $warnings[] = $this->diagnostic('addon_artifact_size_exceeds_max', 'Artifact size exceeds the maximum allowed size.', [
                        $code.' size '.$artifact['size'].' > max '.$maxSize,
                    ]);
                }

                if ($artifactStatus === 'rejected') {
                    $issues[] = $this->diagnostic('addon_artifact_checksum_mismatch', 'Artifact checksum mismatch or rejected.', [
                        $code.' was rejected during quarantine download (checksum mismatch).',
                    ]);
                }

                if ($artifactStatus === 'quarantined' && $row['status'] === 'remote_only') {
                    $info[] = $this->diagnostic('addon_artifact_quarantined_remote_only', 'Artifact quarantined but remote-only addon is not yet installable.', [
                        $code.' is in quarantine but cannot be installed in Phase 3.1.',
                    ]);
                }
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'issues' => $issues,
                'warnings' => $warnings,
                'info' => $info,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $issues === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Addon doctor');

        if ($issues === [] && $warnings === [] && $info === []) {
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

        if ($info !== []) {
            $this->info('Info:');
            $this->renderDiagnostics($info);
        }

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }

    private function isArtifactHostAllowed(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        $config = config('addons-registry', []);
        $allowLocalhost = (bool) ($config['allow_localhost'] ?? false);
        $environment = app()->environment();

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return $allowLocalhost && in_array($environment, ['local', 'testing'], true);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $allowedHosts = array_values(array_filter((array) ($config['allowed_hosts'] ?? []), static fn ($h) => $h !== ''));

        if ($allowedHosts === []) {
            return false;
        }

        return in_array($host, $allowedHosts, true);
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
