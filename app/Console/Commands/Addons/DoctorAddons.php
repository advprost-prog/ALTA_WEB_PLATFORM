<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\AddonHealthCheck;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\ArtifactPromotionManager;
use App\Support\Addons\Registry\ArtifactPromotionStatus;
use Illuminate\Console\Command;

class DoctorAddons extends Command
{
    protected $signature = 'addons:doctor {--json : Output machine-readable JSON}';

    protected $description = 'Report addon manifest, dependency, compatibility, and lifecycle diagnostics.';

    public function handle(AddonHealthCheck $healthCheck, MarketplaceManager $marketplace, ArtifactPromotionManager $promotions): int
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
            $promotion = $promotions->getPromotionReport($code);

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

            if ($promotion['status'] === ArtifactPromotionStatus::READY) {
                $info[] = $this->diagnostic('artifact_ready_for_promotion', 'Artifact is ready for promotion.', [$code]);
            }

            if (($promotion['status'] ?? null) === ArtifactPromotionStatus::PROMOTED) {
                $info[] = $this->diagnostic('artifact_promoted_not_discovered', 'Artifact files are promoted but addon is not discovered/installed/enabled.', [
                    $code.' -> '.($promotion['live_path'] ?? '—'),
                ]);
                if (! is_string($promotion['live_path'] ?? null) || ! is_dir((string) $promotion['live_path'])) {
                    $issues[] = $this->diagnostic('artifact_promotion_live_missing', 'Promoted live directory is missing.', [$code]);
                }
                if (($promotion['rollback_available'] ?? false) === true) {
                    $info[] = $this->diagnostic('artifact_rollback_available', 'Promotion rollback is available.', [$code]);
                }
            }

            foreach (is_array($promotion['diagnostics'] ?? null) ? $promotion['diagnostics'] : [] as $diagnostic) {
                if (! is_array($diagnostic)) {
                    continue;
                }

                $issues[] = $this->diagnostic(
                    (string) ($diagnostic['code'] ?? 'artifact_promotion_diagnostic'),
                    (string) ($diagnostic['message'] ?? 'Promotion diagnostic.'),
                    array_values(array_map('strval', (array) ($diagnostic['details'] ?? []))),
                );
            }

            if (($promotion['promotion_is_stale'] ?? false) === true || ($promotion['status'] ?? null) === ArtifactPromotionStatus::STALE) {
                $issues[] = $this->diagnostic('artifact_promotion_stale', 'Promotion metadata is stale.', [$code]);
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

                $artifactMetadata = $row['artifact_metadata'] ?? null;
                $signatureStatus = $row['signature_status'] ?? ($artifactMetadata['signature_status'] ?? null);
                $manifestStatus = $row['manifest_status'] ?? ($artifactMetadata['manifest_status'] ?? null);
                $trustStatus = $row['trust_status'] ?? ($artifactMetadata['trust_status'] ?? null);
                $reviewStatus = $row['review_status'] ?? ($artifactMetadata['review_status'] ?? 'pending');
                $requireSignature = (bool) (config('addons-registry.trust.require_signature') ?? true);

                if ($reviewStatus === 'pending' && $trustStatus === 'trusted') {
                    $info[] = $this->diagnostic('artifact_review_pending_trusted', 'Trusted artifact is awaiting manual review.', [
                        $code.' review_status=pending.',
                    ]);
                }

                if ($reviewStatus === 'approved') {
                    $info[] = $this->diagnostic('artifact_review_approved', 'Artifact has been manually approved.', [
                        $code.' review_status=approved.',
                    ]);

                    if ($row['approval_is_stale'] ?? false) {
                        $issues[] = $this->diagnostic('artifact_approval_stale', 'Artifact approval integrity snapshot is stale.', [
                            $code.' changed after approval and must be reviewed again.',
                        ]);
                    }

                    if ($trustStatus !== 'trusted') {
                        $issues[] = $this->diagnostic('artifact_approved_untrusted', 'Approved artifact is no longer trusted.', [
                            $code.' trust_status='.($trustStatus ?? 'unknown').'.',
                        ]);
                    }

                    if ($signatureStatus !== 'valid' || $manifestStatus !== 'valid') {
                        $issues[] = $this->diagnostic('artifact_approved_integrity_invalid', 'Approved artifact has invalid signature or manifest state.', [
                            $code.' signature='.($signatureStatus ?? 'unknown').', manifest='.($manifestStatus ?? 'unknown').'.',
                        ]);
                    }

                    if (empty($row['reviewed_by']) || empty($row['reviewed_at'])) {
                        $warnings[] = $this->diagnostic('artifact_review_missing_actor', 'Approved artifact review metadata is incomplete.', [
                            $code.' requires reviewed_by and reviewed_at.',
                        ]);
                    }
                }

                if ($reviewStatus === 'rejected') {
                    $warnings[] = $this->diagnostic('artifact_review_rejected', 'Artifact was manually rejected and remains in quarantine.', [
                        $code.' review_status=rejected.',
                    ]);
                }

                if ($reviewStatus === 'revoked') {
                    $warnings[] = $this->diagnostic('artifact_review_revoked', 'Artifact approval was revoked.', [
                        $code.' review_status=revoked.',
                    ]);
                }

                if (! is_array($artifactMetadata['review_history'] ?? [])) {
                    $warnings[] = $this->diagnostic('artifact_review_history_invalid', 'Artifact review history metadata is malformed.', [
                        $code.' review_history must be an array.',
                    ]);
                }

                $stagingEnabled = (bool) config('addons-registry.staging.enabled', false);
                $stagingStatus = $artifactMetadata['staging_status'] ?? 'not_staged';
                if (! $stagingEnabled) {
                    $info[] = $this->diagnostic('artifact_staging_disabled', 'Artifact staging is disabled.', [$code]);
                } elseif ($stagingStatus === 'staged') {
                    $info[] = $this->diagnostic('artifact_staged', 'Artifact has a staged copy; it is not installed.', [$code]);
                } elseif (($artifactMetadata['staging_is_stale'] ?? false) || $stagingStatus === 'stale') {
                    $issues[] = $this->diagnostic('artifact_staging_stale', 'Artifact staging fingerprint is stale.', [$code]);
                } elseif ($reviewStatus === 'approved' && $trustStatus === 'trusted') {
                    $info[] = $this->diagnostic('artifact_ready_for_staging', 'Artifact is trusted and approved for staging.', [$code]);
                    if (($promotion['status'] ?? null) !== ArtifactPromotionStatus::PROMOTED && (bool) ($promotion['rollback_available'] ?? false) === false) {
                        $info[] = $this->diagnostic('artifact_promotion_disabled', 'Promotion is disabled or not yet available.', [$code]);
                    }
                } elseif ($reviewStatus !== 'approved') {
                    $warnings[] = $this->diagnostic('artifact_promotion_blocked_review', 'Promotion is blocked by review status.', [$code]);
                } elseif ($trustStatus !== 'trusted') {
                    $issues[] = $this->diagnostic('artifact_promotion_blocked_trust', 'Promotion is blocked by trust status.', [$code]);
                } elseif (($artifactMetadata['staging_is_stale'] ?? false) || $stagingStatus === 'stale') {
                    $issues[] = $this->diagnostic('artifact_promotion_blocked_staging', 'Promotion is blocked by staging status.', [$code]);
                } elseif ($stagingStatus !== 'staged') {
                    $warnings[] = $this->diagnostic('artifact_promotion_stale_staging', 'Promotion is blocked by stale or missing staging.', [$code]);
                }

                if ($signatureStatus === 'missing' && $requireSignature) {
                    $warnings[] = $this->diagnostic('addon_artifact_unsigned_required', 'Quarantined artifact has no signature while signatures are required.', [
                        $code.' artifact is unsigned; installs will be blocked until signed.',
                    ]);
                }

                if ($signatureStatus === 'unknown_key') {
                    $warnings[] = $this->diagnostic('addon_artifact_unknown_key', 'Artifact signature uses an unknown trusted key.', [
                        $code.' signature key ['.($artifactMetadata['signature_key_id'] ?? '?').'] is not in trusted_keys.',
                    ]);
                }

                if ($signatureStatus === 'invalid') {
                    $issues[] = $this->diagnostic('addon_artifact_signature_invalid', 'Artifact signature is invalid.', [
                        $code.' signature does not verify against the artifact bytes.',
                    ]);
                }

                if ($signatureStatus === 'not_required') {
                    $info[] = $this->diagnostic('addon_artifact_signature_not_required', 'Signatures are not required for this artifact.', [
                        $code.' trust policy requires no signature (ADDONS_REGISTRY_REQUIRE_SIGNATURE=false).',
                    ]);
                }

                if ($manifestStatus === 'manifest_missing') {
                    $issues[] = $this->diagnostic('addon_artifact_manifest_missing', 'Artifact manifest is missing.', [
                        $code.' artifact zip has no module.json/extension.json/manifest.json.',
                    ]);
                }

                if ($manifestStatus === 'manifest_invalid') {
                    $warnings[] = $this->diagnostic('addon_artifact_manifest_invalid', 'Artifact manifest is not valid JSON.', [
                        $code.' manifest could not be parsed.',
                    ]);
                }

                if ($manifestStatus === 'identity_mismatch') {
                    $issues[] = $this->diagnostic('addon_artifact_manifest_mismatch', 'Artifact manifest code/version does not match the registry item.', [
                        $code.' manifest identity does not match registry item.',
                    ]);
                }

                if ($trustStatus === 'rejected') {
                    $issues[] = $this->diagnostic('addon_artifact_trust_rejected', 'Quarantined artifact trust evaluation rejected the artifact.', [
                        $code.' trust_status=rejected; install is blocked.',
                    ]);
                }

                if ($trustStatus === 'trusted' && $row['status'] === 'remote_only') {
                    $info[] = $this->diagnostic('addon_artifact_trusted_not_installable', 'Artifact is trusted but remote-only addon is not installable yet.', [
                        $code.' is trusted in quarantine but cannot be installed in Phase 3.2.',
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
