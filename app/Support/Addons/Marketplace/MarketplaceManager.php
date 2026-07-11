<?php

namespace App\Support\Addons\Marketplace;

use App\Models\SystemAddon;
use App\Support\Addons\AddonEventLogger;
use App\Support\Addons\AddonManager;
use App\Support\Addons\AddonRegistry;
use App\Support\Addons\PlatformVersion;
use App\Support\Addons\Registry\ArtifactDownloader;
use App\Support\Addons\Registry\ArtifactDownloadResult;
use App\Support\Addons\Registry\ArtifactReviewActor;
use App\Support\Addons\Registry\ArtifactReviewManager;
use App\Support\Addons\Registry\ArtifactReviewResult;
use App\Support\Addons\Registry\ArtifactReviewStatus;
use App\Support\Addons\Registry\ArtifactSignatureVerifier;
use App\Support\Addons\Registry\ArtifactTrustEvaluator;
use App\Support\Addons\Registry\QuarantinedArtifactInspector;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use App\Support\Addons\Registry\RegistryItem;
use App\Support\Addons\Version\VersionComparator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class MarketplaceManager
{
    public function __construct(
        private readonly MarketplaceCatalog $catalog,
        private readonly AddonRegistry $registry,
        private readonly AddonManager $manager,
        private readonly AddonEventLogger $events,
        private readonly DependencyResolver $resolver = new DependencyResolver,
        private readonly VersionComparator $versionComparator = new VersionComparator,
        private readonly PlatformVersion $platformVersion = new PlatformVersion,
        private readonly ?RegistryCatalog $registryCatalog = null,
        private readonly ?ArtifactReviewManager $reviewManager = null,
    ) {}

    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     diagnostics: array<int, string>,
     *     warnings: array<int, string>
     * }
     */
    public function resolve(): array
    {
        $catalog = $this->catalog->load();
        $rows = [];
        $remoteCatalog = $this->loadRemoteCatalog();
        $remoteItems = [];

        foreach ($remoteCatalog['items'] ?? [] as $item) {
            $remoteItems[$item->code] = $item;
        }

        foreach ($catalog['items'] as $item) {
            $remoteItem = $remoteItems[$item->code] ?? null;
            $row = $this->resolveItem($item, $remoteItem);
            $row['source'] = $remoteItem !== null ? 'local_remote' : 'local';
            $row['remote_version'] = $remoteItem?->version;
            $row['local_catalog_version'] = $item->version;
            $row['registry_metadata'] = $remoteItem?->raw ?? [];
            $rows[] = $row;
        }

        foreach ($remoteItems as $code => $remoteItem) {
            if (isset($remoteItems[$code]) && $this->catalog->load()['items']) {
                foreach ($this->catalog->load()['items'] as $localItem) {
                    if ($localItem->code === $code) {
                        continue 2;
                    }
                }
            }

            $row = $this->resolveRemoteOnlyItem($remoteItem);
            $row['source'] = 'remote';
            $row['remote_version'] = $remoteItem->version;
            $row['local_catalog_version'] = null;
            $row['registry_metadata'] = $remoteItem->raw;
            $rows[] = $row;
        }

        $diagnostics = array_merge($catalog['diagnostics'], $remoteCatalog['diagnostics'] ?? []);

        return [
            'rows' => $rows,
            'diagnostics' => $diagnostics,
            'warnings' => $catalog['warnings'],
        ];
    }

    /**
     * @return array{items: list<RegistryItem>, diagnostics: list<string>}
     */
    private function loadRemoteCatalog(): array
    {
        if ($this->registryCatalog === null) {
            return ['items' => [], 'diagnostics' => []];
        }

        return $this->registryCatalog->load();
    }

    /**
     * @return array{
     *     item: MarketplaceItem,
     *     addon: SystemAddon|null,
     *     status: string,
     *     installed_version: string|null,
     *     available_version: string|null,
     *     platform_constraint: string|null,
     *     dependency_constraints: array<string, string|null>,
     *     update_status: string,
     *     compatibility_status: string,
     *     warnings: array<int, string>,
     *     actions: array<int, string>,
     *     dependency_issues: array<int, string>,
     *     dependency_report: array<string, array<string, mixed>>,
     *     can_install_dependencies: bool,
     *     blocked_reasons: array<int, string>
     * }
     */
    private function resolveItem(MarketplaceItem $item, ?RegistryItem $remoteItem = null): array
    {
        $addon = $this->registry->find($item->code);
        $warnings = [];

        if ($item->path !== null && ! is_file(base_path($item->path))) {
            $warnings[] = "Файли маніфесту не знайдено за шляхом [{$item->path}].";
        }

        $status = $this->computeStatus($item, $addon);
        $compatibilityStatus = $this->compatibilityStatus($item);
        $updateStatus = $this->updateStatus($status, $addon, $item);

        $dependencyReport = $this->resolver->resolveItemDependencies(
            $item,
            $this->registry,
            $this->versionComparator,
            $this->catalog->load()['items'],
            $compatibilityStatus,
        );

        $dependencyIssues = [];
        foreach ($dependencyReport as $code => $report) {
            foreach ($report['issues'] as $issue) {
                $dependencyIssues[] = $issue;
            }
        }

        foreach ($dependencyIssues as $issue) {
            $warnings[] = "Залежність: {$issue}";
        }

        if ($addon !== null) {
            $this->logDependencyIssues($item->code, $dependencyIssues);
        }

        $actions = $this->availableActions($status, $item, $dependencyIssues, $updateStatus, $compatibilityStatus);
        $canInstallDependencies = $this->canInstallDependencies($item->code);
        $blockedReasons = $this->getBlockedReasons($item->code);
        $artifactStatus = $this->resolveArtifactStatus($remoteItem ?? $item);

        return [
            'item' => $item,
            'addon' => $addon,
            'status' => $status,
            'installed_version' => $addon?->version,
            'available_version' => $item->version ?: null,
            'platform_constraint' => $item->getPlatformConstraint(),
            'dependency_constraints' => $item->getDependencyConstraints(),
            'update_status' => $updateStatus,
            'compatibility_status' => $compatibilityStatus,
            'warnings' => $warnings,
            'actions' => $actions,
            'dependency_issues' => $dependencyIssues,
            'dependency_report' => $dependencyReport,
            'can_install_dependencies' => $canInstallDependencies,
            'blocked_reasons' => $blockedReasons,
            'artifact' => $remoteItem?->artifact ?? null,
            'artifact_status' => $artifactStatus['status'],
            'artifact_path' => $artifactStatus['path'],
            'artifact_metadata' => $artifactStatus['metadata'],
            'artifact_diagnostics' => $artifactStatus['diagnostics'],
            'signature_status' => $artifactStatus['metadata']['signature_status'] ?? null,
            'manifest_status' => $artifactStatus['metadata']['manifest_status'] ?? null,
            'trust_status' => $artifactStatus['metadata']['trust_status'] ?? null,
            'review_status' => $artifactStatus['metadata']['review_status'] ?? null,
            ...$this->reviewData($item->code),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRemoteOnlyItem(RegistryItem $remoteItem): array
    {
        $item = MarketplaceItem::fromArray([
            'code' => $remoteItem->code,
            'type' => $remoteItem->type,
            'vendor' => $remoteItem->vendor,
            'name' => $remoteItem->name,
            'description' => $remoteItem->description,
            'version' => $remoteItem->version,
            'category' => $remoteItem->category,
            'tags' => $remoteItem->tags,
            'platform_version' => $remoteItem->platformConstraint,
            'dependencies' => $remoteItem->dependencies,
            'is_featured' => $remoteItem->isFeatured,
            'path' => null,
        ]);

        $status = MarketplaceStatus::REMOTE_ONLY;
        $compatibilityStatus = $this->compatibilityStatus($item);
        $updateStatus = UpdateStatus::NOT_INSTALLED;
        $dependencyIssues = [];
        $warnings = [];
        $actions = [];
        $dependencyReport = $this->resolver->resolveItemDependencies(
            $item,
            $this->registry,
            $this->versionComparator,
            $this->catalog->load()['items'],
            $compatibilityStatus,
        );

        foreach ($dependencyReport as $code => $report) {
            foreach ($report['issues'] as $issue) {
                $dependencyIssues[] = $issue;
                $warnings[] = "Залежність: {$issue}";
            }
        }

        $blockedReasons = $this->getBlockedReasons($item->code);
        $canInstallDependencies = $this->canInstallDependencies($item->code);
        $artifactStatus = $this->resolveArtifactStatus($remoteItem);

        return [
            'item' => $item,
            'addon' => null,
            'status' => $status,
            'installed_version' => null,
            'available_version' => $item->version ?: null,
            'platform_constraint' => $item->getPlatformConstraint(),
            'dependency_constraints' => $item->getDependencyConstraints(),
            'update_status' => $updateStatus,
            'compatibility_status' => $compatibilityStatus,
            'warnings' => $warnings,
            'actions' => $actions,
            'dependency_issues' => $dependencyIssues,
            'dependency_report' => $dependencyReport,
            'can_install_dependencies' => $canInstallDependencies,
            'blocked_reasons' => $blockedReasons,
            'artifact' => $remoteItem->artifact ?? null,
            'artifact_status' => $artifactStatus['status'],
            'artifact_path' => $artifactStatus['path'],
            'artifact_metadata' => $artifactStatus['metadata'],
            'artifact_diagnostics' => $artifactStatus['diagnostics'],
            'signature_status' => $artifactStatus['metadata']['signature_status'] ?? null,
            'manifest_status' => $artifactStatus['metadata']['manifest_status'] ?? null,
            'trust_status' => $artifactStatus['metadata']['trust_status'] ?? null,
            'review_status' => $artifactStatus['metadata']['review_status'] ?? null,
            ...$this->reviewData($remoteItem->code),
        ];
    }

    private function computeStatus(MarketplaceItem $item, ?SystemAddon $addon): string
    {
        if (! $item->isValid()) {
            return MarketplaceStatus::INVALID;
        }

        if ($addon === null) {
            if ($item->path !== null && ! is_file(base_path($item->path))) {
                return MarketplaceStatus::MISSING_FILES;
            }

            return MarketplaceStatus::AVAILABLE;
        }

        if ($addon->manifest_path && ! is_file(base_path($addon->manifest_path))) {
            return MarketplaceStatus::MISSING_FILES;
        }

        return match ($addon->status) {
            SystemAddon::STATUS_DISCOVERED => MarketplaceStatus::DISCOVERED,
            SystemAddon::STATUS_INSTALLED => MarketplaceStatus::INSTALLED,
            SystemAddon::STATUS_ENABLED => MarketplaceStatus::ENABLED,
            SystemAddon::STATUS_DISABLED => MarketplaceStatus::DISABLED,
            SystemAddon::STATUS_FAILED => MarketplaceStatus::FAILED,
            SystemAddon::STATUS_REMOVED => MarketplaceStatus::REMOVED,
            default => $addon->status,
        };
    }

    /**
     * @param  array<int, string>  $dependencyIssues
     * @return array<int, string>
     */
    private function availableActions(string $status, MarketplaceItem $item, array $dependencyIssues, string $updateStatus, string $compatibilityStatus): array
    {
        if ($status === MarketplaceStatus::INVALID) {
            return [];
        }

        $incompatible = $compatibilityStatus === CompatibilityStatus::INCOMPATIBLE;

        if ($status === MarketplaceStatus::REMOTE_ONLY) {
            return [];
        }

        $canUpdate = $updateStatus === UpdateStatus::UPDATE_AVAILABLE && ! $incompatible;

        $actions = match ($status) {
            MarketplaceStatus::AVAILABLE, MarketplaceStatus::MISSING_FILES => $incompatible ? [] : ['discover'],
            MarketplaceStatus::DISCOVERED => $incompatible ? [] : ['install'],
            MarketplaceStatus::INSTALLED, MarketplaceStatus::DISABLED => array_values(array_filter([
                $canUpdate ? 'update' : null,
                'enable',
                'uninstall',
            ])),
            MarketplaceStatus::ENABLED => array_values(array_filter([
                $canUpdate ? 'update' : null,
                'disable',
                'uninstall',
            ])),
            MarketplaceStatus::FAILED => ['install', 'uninstall'],
            MarketplaceStatus::REMOVED => ['discover'],
            default => [],
        };

        // Blocking safety: incompatible or unmet dependency constraints block enable.
        if (in_array('enable', $actions, true) && ($dependencyIssues !== [] || $incompatible)) {
            $actions = array_values(array_diff($actions, ['enable']));
        }

        return $actions;
    }

    /**
     * @return array{status: string, path: string|null, metadata: array<string, mixed>|null, diagnostics: list<string>}
     */
    private function resolveArtifactStatus(MarketplaceItem|RegistryItem $item): array
    {
        $artifact = $item->raw['artifact'] ?? null;

        if (! is_array($artifact) || empty($artifact['url'])) {
            return ['status' => 'not_available', 'path' => null, 'metadata' => null, 'diagnostics' => []];
        }

        $downloadsConfig = config('addons-registry.downloads', []);
        $downloadsEnabled = (bool) ($downloadsConfig['enabled'] ?? false);

        if (! $downloadsEnabled) {
            return ['status' => 'downloads_disabled', 'path' => null, 'metadata' => null, 'diagnostics' => []];
        }

        $paths = $this->artifactPaths($item->code, $item->version, $artifact['url']);
        $storage = Storage::disk($paths['disk']);

        if (! $storage->exists($paths['path'])) {
            return ['status' => 'not_downloaded', 'path' => null, 'metadata' => null, 'diagnostics' => []];
        }

        if (! $storage->exists($paths['metadataPath'])) {
            return ['status' => 'not_downloaded', 'path' => $paths['path'], 'metadata' => null, 'diagnostics' => []];
        }

        $metadata = json_decode($storage->get($paths['metadataPath']), true) ?: [];

        if (! is_array($metadata)) {
            return ['status' => 'not_downloaded', 'path' => $paths['path'], 'metadata' => null, 'diagnostics' => []];
        }

        $status = $metadata['status'] ?? 'not_downloaded';

        if ($status === 'rejected') {
            return ['status' => 'rejected', 'path' => $paths['path'], 'metadata' => $metadata, 'diagnostics' => []];
        }

        return ['status' => 'quarantined', 'path' => $paths['path'], 'metadata' => $metadata, 'diagnostics' => []];
    }

    /**
     * @return array{path: string, metadataPath: string, disk: string, directory: string}
     */
    private function artifactPaths(string $code, string $version, string $url): array
    {
        $downloadsConfig = config('addons-registry.downloads', []);
        $disk = (string) ($downloadsConfig['disk'] ?? 'local');
        $quarantinePath = (string) ($downloadsConfig['quarantine_path'] ?? 'addons/quarantine');
        $filename = basename(parse_url($url, PHP_URL_PATH) ?: $code.'.zip');
        $directory = rtrim($quarantinePath.'/'.$code.'/'.$version, '/');
        $path = $directory.'/'.$filename;
        $metadataPath = $directory.'/metadata.json';

        return ['path' => $path, 'metadataPath' => $metadataPath, 'disk' => $disk, 'directory' => $directory];
    }

    /**
     * Inspect an already-downloaded quarantined artifact: verify its signature,
     * inspect its manifest, evaluate trust, and persist the results into
     * metadata.json. Never installs, unpacks into modules/extensions, or runs
     * any code from the artifact.
     *
     * @return array{success: bool, status: string, path: string|null, metadata: array<string, mixed>|null, diagnostics: list<string>, report: array<string, mixed>}
     */
    public function inspectArtifact(string $code): array
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return $this->inspectionFailed('not_available', ["Addon [{$code}] не знайдено у каталозі marketplace."]);
        }

        $artifact = $item->raw['artifact'] ?? null;

        if (! is_array($artifact) || empty($artifact['url'])) {
            return $this->inspectionFailed('not_available', ["Addon [{$code}] не має artifact у registry."]);
        }

        $paths = $this->artifactPaths($code, $item->version, $artifact['url']);
        $storage = Storage::disk($paths['disk']);

        if (! $storage->exists($paths['path'])) {
            return $this->inspectionFailed('not_downloaded', ["Artifact для [{$code}] ще не завантажено у quarantine."]);
        }

        $bytes = $storage->get($paths['path']);
        $calculatedHash = hash('sha256', $bytes);
        $checksumValid = $calculatedHash === ((string) ($artifact['sha256'] ?? ''));

        $trustConfig = config('addons-registry.trust', []);
        $requireSignature = (bool) ($trustConfig['require_signature'] ?? true);
        $trustedKeys = is_array($trustConfig['trusted_keys'] ?? null) ? $trustConfig['trusted_keys'] : [];

        $verifier = new ArtifactSignatureVerifier;
        $signatureResult = $verifier->verify(
            $artifact['signature'] ?? null,
            $bytes,
            $requireSignature,
            $trustedKeys,
        );

        $inspector = new QuarantinedArtifactInspector;
        $manifestResult = $inspector->inspect(
            $storage->path($paths['path']),
            $code,
            $item->version,
        );

        $evaluator = new ArtifactTrustEvaluator;
        $trustResult = $evaluator->evaluate(
            $checksumValid,
            $signatureResult->status,
            $manifestResult->status,
            $requireSignature,
        );

        $metadata = [];
        if ($storage->exists($paths['metadataPath'])) {
            $decoded = json_decode($storage->get($paths['metadataPath']), true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        $now = now()->toIso8601String();
        $metadata = array_merge($metadata, [
            'code' => $code,
            'version' => $item->version,
            'source_url' => $artifact['url'],
            'sha256' => $artifact['sha256'] ?? $calculatedHash,
            'size' => $artifact['size'] ?? strlen($bytes),
            'status' => $metadata['status'] ?? 'quarantined',
            'signature_status' => $signatureResult->status,
            'signature_checked_at' => $now,
            'signature_key_id' => $signatureResult->keyId,
            'manifest_status' => $manifestResult->status,
            'manifest_checked_at' => $now,
            'trust_status' => $trustResult->trustStatus,
            'review_status' => $metadata['review_status'] ?? 'pending',
            'reviewed_at' => $metadata['reviewed_at'] ?? null,
            'reviewed_by' => $metadata['reviewed_by'] ?? null,
            'artifact_diagnostics' => array_values(array_unique([
                ...$signatureResult->diagnostics,
                ...$manifestResult->diagnostics,
                ...$trustResult->diagnostics,
            ])),
        ]);

        $storage->put($paths['metadataPath'], json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $reviewReport = $this->getArtifactReviewReport($code)['report'] ?? null;
        if (is_array($reviewReport)) {
            $metadata['approval_is_stale'] = (bool) ($reviewReport['approval_is_stale'] ?? false);
            $storage->put($paths['metadataPath'], json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $report = [
            'code' => $code,
            'version' => $item->version,
            'path' => $paths['path'],
            'checksum_valid' => $checksumValid,
            'sha256' => $calculatedHash,
            'signature_status' => $signatureResult->status,
            'signature_label' => $signatureResult->label(),
            'signature_key_id' => $signatureResult->keyId,
            'manifest_status' => $manifestResult->status,
            'manifest_label' => $manifestResult->label(),
            'manifest_path' => $manifestResult->manifestPath,
            'trust_status' => $trustResult->trustStatus,
            'trust_label' => $trustResult->label(),
            'review_status' => $metadata['review_status'],
            'diagnostics' => $metadata['artifact_diagnostics'],
        ];

        return [
            'success' => true,
            'status' => $trustResult->trustStatus,
            'path' => $paths['path'],
            'metadata' => $metadata,
            'diagnostics' => $metadata['artifact_diagnostics'],
            'report' => $report,
        ];
    }

    /**
     * @return array{success: bool, status: string, path: string|null, metadata: array<string, mixed>|null, diagnostics: list<string>, report: array<string, mixed>}
     */
    public function getArtifactInspectionReport(string $code): array
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return $this->inspectionFailed('not_available', ["Addon [{$code}] не знайдено у каталозі marketplace."]);
        }

        $artifact = $item->raw['artifact'] ?? null;

        if (! is_array($artifact) || empty($artifact['url'])) {
            return $this->inspectionFailed('not_available', ["Addon [{$code}] не має artifact у registry."]);
        }

        $paths = $this->artifactPaths($code, $item->version, $artifact['url']);
        $storage = Storage::disk($paths['disk']);

        if (! $storage->exists($paths['path'])) {
            return $this->inspectionFailed('not_downloaded', ["Artifact для [{$code}] ще не завантажено у quarantine."]);
        }

        $metadata = [];
        if ($storage->exists($paths['metadataPath'])) {
            $decoded = json_decode($storage->get($paths['metadataPath']), true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return [
            'success' => true,
            'status' => $metadata['trust_status'] ?? 'not_inspected',
            'path' => $paths['path'],
            'metadata' => $metadata,
            'diagnostics' => $metadata['artifact_diagnostics'] ?? [],
            'report' => [
                'code' => $code,
                'version' => $item->version,
                'path' => $paths['path'],
                'sha256' => $metadata['sha256'] ?? null,
                'signature_status' => $metadata['signature_status'] ?? null,
                'manifest_status' => $metadata['manifest_status'] ?? null,
                'trust_status' => $metadata['trust_status'] ?? null,
                'review_status' => $metadata['review_status'] ?? null,
                'diagnostics' => $metadata['artifact_diagnostics'] ?? [],
            ],
        ];
    }

    public function getArtifactTrustStatus(string $code): ?string
    {
        $report = $this->getArtifactInspectionReport($code);

        return $report['report']['trust_status'] ?? null;
    }

    /* ---------------------------------------------------------------------
     | Quarantine review workflow (Phase 3.3)
     | ------------------------------------------------------------------- */

    public function approveArtifact(string $code, ?string $note, mixed $actor): ArtifactReviewResult
    {
        return $this->reviewManager()->approve($code, $note, $this->toActor($actor));
    }

    public function rejectArtifact(string $code, string $note, mixed $actor): ArtifactReviewResult
    {
        return $this->reviewManager()->reject($code, $note, $this->toActor($actor));
    }

    public function revokeArtifactApproval(string $code, ?string $note, mixed $actor): ArtifactReviewResult
    {
        return $this->reviewManager()->revoke($code, $note, $this->toActor($actor));
    }

    public function getArtifactReviewReport(string $code): array
    {
        return $this->reviewManager()->getReviewReport($code);
    }

    public function canApproveArtifact(string $code): bool
    {
        return $this->reviewManager()->canApprove($code);
    }

    public function canRejectArtifact(string $code): bool
    {
        return $this->reviewManager()->canReject($code);
    }

    public function canRevokeArtifact(string $code): bool
    {
        return $this->reviewManager()->canRevoke($code);
    }

    /**
     * @return list<string>
     */
    public function getReviewBlockedReasons(string $code): array
    {
        return $this->reviewManager()->getReviewBlockedReasons($code);
    }

    private function reviewManager(): ArtifactReviewManager
    {
        return $this->reviewManager ?? app(ArtifactReviewManager::class);
    }

    private function toActor(mixed $actor): ArtifactReviewActor
    {
        if ($actor instanceof ArtifactReviewActor) {
            return $actor;
        }

        if ($actor instanceof Authenticatable) {
            return ArtifactReviewActor::fromUser($actor);
        }

        return ArtifactReviewActor::cli();
    }

    /**
     * Merge review workflow data into a resolved marketplace row.
     *
     * @return array<string, mixed>
     */
    private function reviewData(string $code): array
    {
        $report = $this->reviewManager()->getReviewReport($code)['report'] ?? null;

        if ($report === null) {
            return [
                'review_label' => ArtifactReviewStatus::label(ArtifactReviewStatus::PENDING),
                'review_history' => [],
                'reviewed_at' => null,
                'reviewed_by' => null,
                'reviewed_by_name' => null,
                'review_note' => null,
                'approval_is_stale' => false,
                'can_approve' => false,
                'can_reject' => false,
                'can_revoke' => false,
                'review_blocked_reasons' => [],
            ];
        }

        return [
            'review_label' => $report['review_label'],
            'review_history' => $report['review_history'],
            'reviewed_at' => $report['reviewed_at'],
            'reviewed_by' => $report['reviewed_by'],
            'reviewed_by_name' => $report['reviewed_by_name'],
            'review_note' => $report['review_note'],
            'approval_is_stale' => $report['approval_is_stale'],
            'can_approve' => $report['can_approve'],
            'can_reject' => $report['can_reject'],
            'can_revoke' => $report['can_revoke'],
            'review_blocked_reasons' => $report['review_blocked_reasons'],
        ];
    }

    /**
     * @param  list<string>  $diagnostics
     * @return array{success: bool, status: string, path: string|null, metadata: array<string, mixed>|null, diagnostics: list<string>, report: array<string, mixed>}
     */
    private function inspectionFailed(string $status, array $diagnostics): array
    {
        return [
            'success' => false,
            'status' => $status,
            'path' => null,
            'metadata' => null,
            'diagnostics' => $diagnostics,
            'report' => [],
        ];
    }

    private function compatibilityStatus(MarketplaceItem $item): string
    {
        $constraint = $item->getPlatformConstraint();

        if ($constraint === null || $constraint === '' || $constraint === '*') {
            return CompatibilityStatus::UNKNOWN;
        }

        return $this->versionComparator->satisfies($this->platformVersion->version(), $constraint)
            ? CompatibilityStatus::COMPATIBLE
            : CompatibilityStatus::INCOMPATIBLE;
    }

    private function updateStatus(string $status, ?SystemAddon $addon, MarketplaceItem $item): string
    {
        if (! in_array($status, [
            MarketplaceStatus::INSTALLED,
            MarketplaceStatus::ENABLED,
            MarketplaceStatus::DISABLED,
            MarketplaceStatus::FAILED,
        ], true)) {
            return UpdateStatus::NOT_INSTALLED;
        }

        return $this->versionComparator->compareInstalled((string) ($addon?->version ?? ''), (string) $item->version);
    }

    /**
     * @return array<int, string>
     */
    public function dependencyIssues(MarketplaceItem $item): array
    {
        $issues = [];

        foreach ($item->getDependencies() as $dependency) {
            $dependencyCode = $dependency['code'];
            $constraint = $dependency['constraint'];
            $dependencyAddon = $this->registry->find($dependencyCode);

            if (! $dependencyAddon || ! $dependencyAddon->is_installed) {
                $issues[] = "Залежність [{$dependencyCode}] не встановлено.";

                continue;
            }

            if (! $dependencyAddon->is_enabled) {
                $issues[] = "Залежність [{$dependencyCode}] вимкнено.";
            }

            if ($constraint !== null && $constraint !== '' && $constraint !== '*'
                && ! $this->versionComparator->satisfies((string) $dependencyAddon->version, $constraint)) {
                $issues[] = "Версія залежності [{$dependencyCode}] ({$dependencyAddon->version}) не відповідає обмеженню [{$constraint}].";
            }
        }

        return $issues;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getDependencyReport(string $code): array
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return [];
        }

        $compatibilityStatus = $this->compatibilityStatus($item);

        return $this->resolver->resolveItemDependencies(
            $item,
            $this->registry,
            $this->versionComparator,
            $this->catalog->load()['items'],
            $compatibilityStatus,
        );
    }

    public function canInstallDependencies(string $code): bool
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return false;
        }

        $report = $this->getDependencyReport($code);

        if ($report === []) {
            return false;
        }

        $graph = $this->resolver->buildGraph($this->catalog->load()['items']);
        $cycles = $this->resolver->detectCycles($graph);

        if ($cycles !== []) {
            return false;
        }

        foreach ($report as $dependencyReport) {
            $hasBlockingIssue = false;
            $hasOnlyNotInstalledIssue = false;

            foreach ($dependencyReport['issues'] as $issue) {
                if (str_contains($issue, 'не встановлено і локальні файли відсутні')) {
                    $hasBlockingIssue = true;
                } elseif (str_contains($issue, 'не встановлено')) {
                    $hasOnlyNotInstalledIssue = true;
                } elseif (str_contains($issue, 'несумісна')) {
                    $hasBlockingIssue = true;
                } elseif (str_contains($issue, 'некоректна')) {
                    $hasBlockingIssue = true;
                } elseif (str_contains($issue, 'відсутній маніфест')) {
                    $hasBlockingIssue = true;
                } else {
                    $hasBlockingIssue = true;
                }
            }

            if ($hasBlockingIssue) {
                return false;
            }

            if (! $hasOnlyNotInstalledIssue) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function getBlockedReasons(string $code): array
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return ['Каталог не містить item ['.$code.'].'];
        }

        $addon = $this->registry->find($code);
        $compatibilityStatus = $this->compatibilityStatus($item);
        $dependencyIssues = $this->dependencyIssues($item);
        $blocked = [];

        if ($compatibilityStatus === CompatibilityStatus::INCOMPATIBLE) {
            $blocked[] = 'Несумісність з платформою.';
        }

        foreach ($dependencyIssues as $issue) {
            $blocked[] = $issue;
        }

        return $blocked;
    }

    public function canEnable(string $code): bool
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return false;
        }

        $addon = $this->registry->find($code);

        if ($addon === null || ! $addon->is_installed) {
            return false;
        }

        $compatibilityStatus = $this->compatibilityStatus($item);

        if ($compatibilityStatus === CompatibilityStatus::INCOMPATIBLE) {
            return false;
        }

        $dependencyIssues = $this->dependencyIssues($item);

        return $dependencyIssues === [];
    }

    public function installDependencies(string $code): array
    {
        $item = $this->findItem($code);

        if ($item === null) {
            throw new RuntimeException("Каталог не містить item [{$code}].");
        }

        $report = $this->getDependencyReport($code);
        $installed = [];
        $graph = $this->resolver->buildGraph($this->catalog->load()['items']);
        $cycles = $this->resolver->detectCycles($graph);

        if ($cycles !== []) {
            throw new RuntimeException('Неможливо встановити залежності: виявлено циклічні залежності — '.implode(', ', $cycles));
        }

        foreach ($report as $dependencyCode => $dependencyReport) {
            $hasBlockingIssue = false;

            foreach ($dependencyReport['issues'] as $issue) {
                if (str_contains($issue, 'не встановлено')) {
                    continue;
                }

                $hasBlockingIssue = true;
            }

            if ($hasBlockingIssue) {
                throw new RuntimeException('Неможливо встановити залежність ['.$dependencyCode.']: '.implode(' ', $dependencyReport['issues']));
            }

            $dependencyAddon = $this->registry->find($dependencyCode);

            if ($dependencyAddon === null || ! $dependencyAddon->is_installed) {
                $installedAddon = $this->manager->install($dependencyCode);
                $installed[] = $installedAddon->code;
                $this->events->info($dependencyCode, 'marketplace_dependency_installed', 'Dependency installed via parent addon.', [
                    'parent' => $code,
                ]);
            }
        }

        if ($installed !== []) {
            $parentAddon = $this->registry->find($code);

            if ($parentAddon !== null) {
                $this->events->info($code, 'marketplace_dependencies_installed', 'Dependencies installed.', [
                    'dependencies' => $installed,
                ]);
            }
        }

        return $installed;
    }

    /**
     * @return array{discovered: int, invalid: int, duplicates: int}
     */
    public function discover(): array
    {
        return $this->manager->discover();
    }

    public function install(string $code): SystemAddon
    {
        return $this->manager->install($code);
    }

    public function enable(string $code): SystemAddon
    {
        $item = $this->findItem($code);

        if ($item !== null) {
            $issues = $this->dependencyIssues($item);

            if ($issues !== []) {
                $message = 'Неможливо увімкнути: невиконані залежності — '.implode(' ', $issues);

                if ($this->registry->find($code) !== null) {
                    $this->events->error($code, 'marketplace_enable_blocked', $message, $issues);
                }

                throw new RuntimeException($message);
            }
        }

        return $this->manager->enable($code);
    }

    /**
     * Local update: records the available (catalog) version as the new installed
     * version without downloading any files. Keeps the current enabled/disabled
     * status untouched. Blocked when incompatible or when no update is available.
     */
    public function update(string $code): SystemAddon
    {
        $addon = $this->registry->find($code);

        if ($addon === null) {
            throw new RuntimeException("Addon [{$code}] не знайдено. Спочатку виконайте discover.");
        }

        $item = $this->findItem($code);

        if ($item === null) {
            throw new RuntimeException("Каталог не містить item [{$code}].");
        }

        $row = $this->resolveItem($item);

        if ($row['compatibility_status'] === CompatibilityStatus::INCOMPATIBLE) {
            throw new RuntimeException("Неможливо оновити [{$code}]: несумісно з поточною версією платформи.");
        }

        if ($row['update_status'] !== UpdateStatus::UPDATE_AVAILABLE) {
            throw new RuntimeException("Оновлення для [{$code}] недоступне (поточна версія вже актуальна або невідома).");
        }

        $dependencyIssues = $this->dependencyIssues($item);

        if ($dependencyIssues !== []) {
            throw new RuntimeException('Неможливо оновити ['.$code.']: невиконані залежності — '.implode(' ', $dependencyIssues));
        }

        $previousVersion = $addon->version;

        $addon->forceFill([
            'version' => $item->version,
            'is_installed' => true,
        ])->save();

        $this->events->info($code, 'marketplace_updated', "Addon updated to {$item->version}.", [
            'from' => $previousVersion,
            'to' => $item->version,
        ]);

        return $addon->refresh();
    }

    /**
     * @param  array<int, string>  $issues
     */
    private function logDependencyIssues(string $code, array $issues): void
    {
        if ($issues === []) {
            return;
        }

        foreach ($issues as $issue) {
            if (str_contains($issue, 'вимкнено')) {
                $this->events->warning($code, 'marketplace_dependency_disabled', 'Marketplace dependency is not enabled.', [
                    'issue' => $issue,
                ]);
            } else {
                $this->events->warning($code, 'marketplace_dependency_missing', 'Marketplace dependency is not installed.', [
                    'issue' => $issue,
                ]);
            }
        }
    }

    public function disable(string $code): SystemAddon
    {
        return $this->manager->disable($code);
    }

    public function uninstall(string $code): SystemAddon
    {
        return $this->manager->uninstall($code);
    }

    /**
     * @return array{status: string, path: string|null, metadata: array<string, mixed>|null, diagnostics: list<string>}
     */
    public function getArtifactStatus(string $code): array
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return ['status' => 'not_available', 'path' => null, 'metadata' => null, 'diagnostics' => ['Catalog item not found.']];
        }

        return $this->resolveArtifactStatus($item);
    }

    /**
     * Download a remote-only artifact into quarantine.
     *
     * Never installs, unpacks into modules/extensions, or executes remote code.
     * Returns a structured result describing the outcome and stored path.
     */
    public function downloadArtifact(string $code): ArtifactDownloadResult
    {
        $item = $this->findItem($code);

        if ($item === null) {
            return ArtifactDownloadResult::failed('not_available', ["Addon [{$code}] не знайдено у каталозі marketplace."]);
        }

        $registryItem = RegistryItem::fromArray($item->raw);
        $downloader = new ArtifactDownloader(new RegistryClient(config('addons-registry', [])), config('addons-registry', []));

        return $downloader->download($registryItem);
    }

    private function findItem(string $code): ?MarketplaceItem
    {
        foreach ($this->catalog->load()['items'] as $item) {
            if ($item->code === $code) {
                return $item;
            }
        }

        $remoteCatalog = $this->loadRemoteCatalog();

        foreach ($remoteCatalog['items'] ?? [] as $item) {
            if ($item->code === $code) {
                return MarketplaceItem::fromArray($item->raw);
            }
        }

        return null;
    }
}
