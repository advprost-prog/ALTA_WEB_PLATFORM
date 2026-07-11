<?php

namespace App\Support\Addons\Registry;

use App\Models\SystemAddon;
use App\Support\Addons\AddonEventLogger;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * Manual quarantine review workflow for remote addon artifacts (Phase 3.3).
 *
 * Administrative control only — approve/reject/revoke never unpack, install,
 * or execute any code from the artifact. Approving does NOT make the addon
 * installable; a later unpack/install phase is still required and remains
 * blocked until then.
 *
 * All decisions read the artifact from quarantine, re-verify integrity
 * (checksum + signature + manifest + trust), and persist an append-only
 * review history plus an integrity snapshot used for staleness detection.
 */
final class ArtifactReviewManager
{
    public function __construct(
        private readonly RegistryCatalog $catalog,
        private readonly ArtifactSignatureVerifier $verifier,
        private readonly QuarantinedArtifactInspector $inspector,
        private readonly ArtifactTrustEvaluator $evaluator,
        private readonly AddonEventLogger $events,
    ) {}

    /**
     * Approve a quarantined artifact for the next (still-blocked) phase.
     */
    public function approve(string $code, ?string $note, ArtifactReviewActor $actor): ArtifactReviewResult
    {
        $state = $this->resolveState($code);

        if ($state === null) {
            return new ArtifactReviewResult(false, 'not_available', null, ['Artifact не знайдено в quarantine.']);
        }

        $reasons = $this->blockedReasons($state);

        if ($reasons !== []) {
            return new ArtifactReviewResult(false, 'blocked', $state['review_status'], $reasons);
        }

        $now = now()->toIso8601String();

        $metadata = $state['metadata'];
        $metadata['review_status'] = ArtifactReviewStatus::APPROVED;
        $metadata['reviewed_at'] = $now;
        $metadata['reviewed_by'] = $actor->id;
        $metadata['reviewed_by_name'] = $actor->name;
        $metadata['review_note'] = $note;
        $metadata['approval_is_stale'] = false;
        $metadata['approved_integrity_snapshot'] = $this->snapshot($state);
        $metadata['review_history'] = $this->appendHistory(
            $metadata['review_history'] ?? null,
            'approved',
            $actor,
            $note,
        );

        $this->persist($state['metadataPath'], $metadata);

        $this->logEvent($code, 'marketplace_artifact_approved', 'Artifact approved for staged unpack.', [
            'version' => $state['version'],
            'actor' => $actor->toHistoryEntry(),
            'note' => $note,
            'trust_status' => $state['trust_status'],
            'signature_status' => $state['signature_status'],
            'manifest_status' => $state['manifest_status'],
        ]);

        return new ArtifactReviewResult(true, 'approved', ArtifactReviewStatus::APPROVED, [], $this->report($code, $metadata, $state));
    }

    /**
     * Reject a quarantined artifact. Keeps the ZIP in quarantine for audit.
     */
    public function reject(string $code, string $note, ArtifactReviewActor $actor): ArtifactReviewResult
    {
        $state = $this->resolveState($code);

        if ($state === null) {
            return new ArtifactReviewResult(false, 'not_available', null, ['Artifact не знайдено в quarantine.']);
        }

        $reviewStatus = (string) ($state['review_status'] ?? ArtifactReviewStatus::PENDING);

        if ($reviewStatus === ArtifactReviewStatus::APPROVED) {
            return new ArtifactReviewResult(
                false,
                'blocked',
                $reviewStatus,
                ['Схвалений artifact слід спочатку відкликати (revoke), перш ніж відхиляти.'],
            );
        }

        if ($reviewStatus === ArtifactReviewStatus::REJECTED) {
            return new ArtifactReviewResult(
                false,
                'blocked',
                $reviewStatus,
                ['Artifact вже відхилено.'],
            );
        }

        $requireNote = (bool) (Config::get('addons-registry.review.require_note_on_reject') ?? true);

        if ($requireNote && trim($note) === '') {
            return new ArtifactReviewResult(
                false,
                'blocked',
                $reviewStatus,
                ['Причина відхилення обов’язкова (review.require_note_on_reject=true).'],
            );
        }

        $now = now()->toIso8601String();

        $metadata = $state['metadata'];
        $metadata['review_status'] = ArtifactReviewStatus::REJECTED;
        $metadata['reviewed_at'] = $now;
        $metadata['reviewed_by'] = $actor->id;
        $metadata['reviewed_by_name'] = $actor->name;
        $metadata['review_note'] = $note;
        $metadata['review_history'] = $this->appendHistory(
            $metadata['review_history'] ?? null,
            'rejected',
            $actor,
            $note,
        );

        $this->persist($state['metadataPath'], $metadata);

        $this->logEvent($code, 'marketplace_artifact_rejected', 'Artifact rejected.', [
            'version' => $state['version'],
            'actor' => $actor->toHistoryEntry(),
            'note' => $note,
            'trust_status' => $state['trust_status'],
            'signature_status' => $state['signature_status'],
            'manifest_status' => $state['manifest_status'],
        ]);

        return new ArtifactReviewResult(true, 'rejected', ArtifactReviewStatus::REJECTED, [], $this->report($code, $metadata, $state));
    }

    /**
     * Revoke a previously approved artifact. Future readiness is blocked again.
     */
    public function revoke(string $code, ?string $note, ArtifactReviewActor $actor): ArtifactReviewResult
    {
        $state = $this->resolveState($code);

        if ($state === null) {
            return new ArtifactReviewResult(false, 'not_available', null, ['Artifact не знайдено в quarantine.']);
        }

        $reviewStatus = (string) ($state['review_status'] ?? ArtifactReviewStatus::PENDING);

        if ($reviewStatus !== ArtifactReviewStatus::APPROVED) {
            return new ArtifactReviewResult(
                false,
                'blocked',
                $reviewStatus,
                ['Відкликати можна лише схвалений artifact (review_status=approved).'],
            );
        }

        $allowRevoke = (bool) (Config::get('addons-registry.review.allow_revoke') ?? true);

        if (! $allowRevoke) {
            return new ArtifactReviewResult(
                false,
                'blocked',
                $reviewStatus,
                ['Відкликання схвалення вимкнено (review.allow_revoke=false).'],
            );
        }

        $now = now()->toIso8601String();

        $metadata = $state['metadata'];
        $metadata['review_status'] = ArtifactReviewStatus::REVOKED;
        $metadata['approval_revoked_at'] = $now;
        $metadata['approval_revoked_by'] = $actor->id;
        $metadata['approval_revoked_by_name'] = $actor->name;
        $metadata['approval_revoke_note'] = $note;
        $metadata['review_history'] = $this->appendHistory(
            $metadata['review_history'] ?? null,
            'revoked',
            $actor,
            $note,
        );

        $this->persist($state['metadataPath'], $metadata);

        $this->logEvent($code, 'marketplace_artifact_approval_revoked', 'Artifact approval revoked.', [
            'version' => $state['version'],
            'actor' => $actor->toHistoryEntry(),
            'note' => $note,
            'trust_status' => $state['trust_status'],
            'signature_status' => $state['signature_status'],
            'manifest_status' => $state['manifest_status'],
        ]);

        return new ArtifactReviewResult(true, 'revoked', ArtifactReviewStatus::REVOKED, [], $this->report($code, $metadata, $state));
    }

    /**
     * @return array{success: bool, status: string, review_status: string|null, diagnostics: list<string>, report: array<string, mixed>|null}
     */
    public function getReviewReport(string $code): array
    {
        $state = $this->resolveState($code);

        if ($state === null) {
            return [
                'success' => false,
                'status' => 'not_available',
                'review_status' => null,
                'diagnostics' => ['Artifact не знайдено в quarantine.'],
                'report' => null,
            ];
        }

        $report = $this->report($code, $state['metadata'], $state);

        return [
            'success' => true,
            'status' => 'ok',
            'review_status' => $report['review_status'],
            'diagnostics' => $report['diagnostics'] ?? [],
            'report' => $report,
        ];
    }

    public function canApprove(string $code): bool
    {
        return $this->blockedReasons($this->resolveState($code) ?? []) === [];
    }

    public function canReject(string $code): bool
    {
        $state = $this->resolveState($code);

        if ($state === null) {
            return false;
        }

        $reviewStatus = (string) ($state['review_status'] ?? ArtifactReviewStatus::PENDING);

        return in_array($reviewStatus, [ArtifactReviewStatus::PENDING, ArtifactReviewStatus::REVOKED], true);
    }

    public function canRevoke(string $code): bool
    {
        $state = $this->resolveState($code);

        if ($state === null) {
            return false;
        }

        $reviewStatus = (string) ($state['review_status'] ?? ArtifactReviewStatus::PENDING);

        return $reviewStatus === ArtifactReviewStatus::APPROVED
            && (bool) (Config::get('addons-registry.review.allow_revoke') ?? true);
    }

    /**
     * @return list<string>
     */
    public function getReviewBlockedReasons(string $code): array
    {
        return $this->blockedReasons($this->resolveState($code) ?? []);
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return list<string>
     */
    private function blockedReasons(?array $state): array
    {
        if ($state === null) {
            return ['Artifact не знайдено в quarantine.'];
        }

        $reviewConfig = Config::get('addons-registry.review', []);
        $requireTrusted = (bool) ($reviewConfig['require_trusted'] ?? true);
        $requireSignature = (bool) (Config::get('addons-registry.trust.require_signature') ?? true);

        $reasons = [];

        if (! (bool) ($reviewConfig['enabled'] ?? true)) {
            $reasons[] = 'Review workflow вимкнено (review.enabled=false).';
        }

        if ((string) ($state['download_status'] ?? '') !== 'quarantined') {
            $reasons[] = 'Artifact не перебуває у quarantine (download_status='.(string) ($state['download_status'] ?? 'unknown').').';
        }

        if (! ($state['checksum_valid'] ?? false)) {
            $reasons[] = 'Checksum недійсний.';
        }

        if ((string) ($state['manifest_status'] ?? '') !== QuarantinedArtifactInspector::STATUS_VALID) {
            $reasons[] = 'Manifest невалідний або відсутній ('.(string) ($state['manifest_status'] ?? 'unknown').').';
        }

        if ($requireSignature && (string) ($state['signature_status'] ?? '') !== ArtifactSignatureVerifier::STATUS_VALID) {
            $reasons[] = 'Підпис недійсний/відсутній ('.(string) ($state['signature_status'] ?? 'unknown').').';
        }

        if ($requireTrusted && (string) ($state['trust_status'] ?? '') !== ArtifactTrustEvaluator::TRUST_TRUSTED) {
            $reasons[] = 'Artifact не довірений (trust_status='.(string) ($state['trust_status'] ?? 'unknown').').';
        }

        if ((string) ($state['review_status'] ?? ArtifactReviewStatus::PENDING) === ArtifactReviewStatus::APPROVED) {
            $reasons[] = 'Artifact вже схвалено.';
        }

        if ((string) ($state['review_status'] ?? ArtifactReviewStatus::PENDING) === ArtifactReviewStatus::REJECTED) {
            $reasons[] = 'Artifact відхилено.';
        }

        if ((string) ($state['code'] ?? '') !== (string) ($state['registry_code'] ?? '')) {
            $reasons[] = 'Code artifact не збігається з registry.';
        }

        if ((string) ($state['version'] ?? '') !== (string) ($state['registry_version'] ?? '')) {
            $reasons[] = 'Version artifact не збігається з registry.';
        }

        return $reasons;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveState(string $code): ?array
    {
        $items = $this->catalog->load()['items'] ?? [];

        $registryItem = null;
        foreach ($items as $item) {
            if ($item->code === $code) {
                $registryItem = $item;

                break;
            }
        }

        if ($registryItem === null) {
            return null;
        }

        $artifact = $registryItem->raw['artifact'] ?? null;

        if (! is_array($artifact) || empty($artifact['url'])) {
            return null;
        }

        $downloadsConfig = Config::get('addons-registry.downloads', []);
        $disk = (string) ($downloadsConfig['disk'] ?? 'addons');
        $quarantinePath = (string) ($downloadsConfig['quarantine_path'] ?? 'addons/quarantine');
        $filename = basename(parse_url($artifact['url'], PHP_URL_PATH) ?: $code.'.zip');
        $directory = rtrim($quarantinePath.'/'.$code.'/'.$registryItem->version, '/');
        $path = $directory.'/'.$filename;
        $metadataPath = $directory.'/metadata.json';

        $storage = Storage::disk($disk);

        if (! $storage->exists($path) || ! $storage->exists($metadataPath)) {
            return null;
        }

        $bytes = $storage->get($path);
        $calculatedHash = hash('sha256', $bytes);
        $checksumValid = $calculatedHash === ((string) ($artifact['sha256'] ?? ''));

        $trustConfig = Config::get('addons-registry.trust', []);
        $requireSignature = (bool) ($trustConfig['require_signature'] ?? true);
        $trustedKeys = is_array($trustConfig['trusted_keys'] ?? null) ? $trustConfig['trusted_keys'] : [];

        $signatureResult = $this->verifier->verify(
            $artifact['signature'] ?? null,
            $bytes,
            $requireSignature,
            $trustedKeys,
        );

        $manifestResult = $this->inspector->inspect($storage->path($path), $code, $registryItem->version);

        $trustResult = $this->evaluator->evaluate(
            $checksumValid,
            $signatureResult->status,
            $manifestResult->status,
            $requireSignature,
        );

        $metadata = json_decode($storage->get($metadataPath), true);
        $metadata = is_array($metadata) ? $metadata : [];

        $reviewStatus = (string) ($metadata['review_status'] ?? ArtifactReviewStatus::PENDING);

        return [
            'registry_item' => $registryItem,
            'registry_code' => $registryItem->code,
            'registry_version' => $registryItem->version,
            'code' => $code,
            'version' => $registryItem->version,
            'path' => $path,
            'metadataPath' => $metadataPath,
            'metadata' => $metadata,
            'review_status' => $reviewStatus,
            'download_status' => $metadata['status'] ?? 'quarantined',
            'checksum_valid' => $checksumValid,
            'signature_status' => $signatureResult->status,
            'manifest_status' => $manifestResult->status,
            'trust_status' => $trustResult->trustStatus,
            'signature_key_id' => $signatureResult->keyId,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function snapshot(array $state): array
    {
        return [
            'code' => $state['code'],
            'version' => $state['version'],
            'sha256' => hash('sha256', Storage::disk(Config::get('addons-registry.downloads.disk', 'addons'))->get($state['path'])),
            'size' => (int) ($state['metadata']['size'] ?? 0),
            'signature_key_id' => $state['signature_key_id'],
            'signature_status' => $state['signature_status'],
            'manifest_status' => $state['manifest_status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function report(string $code, array $metadata, array $state): array
    {
        $snapshot = $metadata['approved_integrity_snapshot'] ?? null;
        $approvalIsStale = false;
        $historyMalformed = ! is_array($metadata['review_history'] ?? []);

        if ((string) ($metadata['review_status'] ?? '') === ArtifactReviewStatus::APPROVED && is_array($snapshot)) {
            $current = $this->snapshot($state);
            $approvalIsStale = $current['code'] !== ($snapshot['code'] ?? null)
                || $current['version'] !== ($snapshot['version'] ?? null)
                || $current['sha256'] !== ($snapshot['sha256'] ?? null)
                || $current['size'] !== ($snapshot['size'] ?? null)
                || (string) ($current['signature_key_id'] ?? '') !== (string) ($snapshot['signature_key_id'] ?? '')
                || $current['signature_status'] !== ($snapshot['signature_status'] ?? null)
                || $current['manifest_status'] !== ($snapshot['manifest_status'] ?? null);
        }

        return [
            'code' => $code,
            'version' => $state['version'],
            'review_status' => $metadata['review_status'] ?? ArtifactReviewStatus::PENDING,
            'review_label' => ArtifactReviewStatus::label($metadata['review_status'] ?? ArtifactReviewStatus::PENDING),
            'reviewed_at' => $metadata['reviewed_at'] ?? null,
            'reviewed_by' => $metadata['reviewed_by'] ?? null,
            'reviewed_by_name' => $metadata['reviewed_by_name'] ?? null,
            'review_note' => $metadata['review_note'] ?? null,
            'approval_revoked_at' => $metadata['approval_revoked_at'] ?? null,
            'approval_revoked_by' => $metadata['approval_revoked_by'] ?? null,
            'approval_revoked_by_name' => $metadata['approval_revoked_by_name'] ?? null,
            'approval_revoke_note' => $metadata['approval_revoke_note'] ?? null,
            'approval_is_stale' => $approvalIsStale,
            'trust_status' => $state['trust_status'],
            'signature_status' => $state['signature_status'],
            'manifest_status' => $state['manifest_status'],
            'diagnostics' => $historyMalformed ? ['Review history metadata має некоректний формат.'] : [],
            'review_history' => is_array($metadata['review_history'] ?? null) ? $metadata['review_history'] : [],
            'review_history_malformed' => $historyMalformed,
            'can_approve' => $this->canApprove($code),
            'can_reject' => $this->canReject($code),
            'can_revoke' => $this->canRevoke($code),
            'review_blocked_reasons' => $this->blockedReasons($state),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function appendHistory(mixed $history, string $action, ArtifactReviewActor $actor, ?string $note): array
    {
        $history = is_array($history) ? $history : [];

        $history[] = array_merge([
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_type' => $actor->type,
            'note' => $note,
            'created_at' => now()->toIso8601String(),
        ]);

        return $history;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function persist(string $metadataPath, array $metadata): void
    {
        Storage::disk(Config::get('addons-registry.downloads.disk', 'addons'))->put(
            $metadataPath,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logEvent(string $code, string $event, string $message, array $context): void
    {
        // Avoid FK violation on system_addon_events: remote-only addons have no
        // system_addons row, so we only log to the DB when the addon exists.
        // The metadata.json review_history remains the authoritative audit trail.
        if (SystemAddon::where('code', $code)->exists()) {
            $this->events->info($code, $event, $message, $context);
        }
    }
}
