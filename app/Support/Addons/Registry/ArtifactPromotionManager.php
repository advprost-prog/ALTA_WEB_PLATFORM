<?php

namespace App\Support\Addons\Registry;

use App\Models\SystemAddon;
use App\Support\Addons\AddonEventLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ArtifactPromotionManager
{
    public function __construct(
        private readonly RegistryCatalog $catalog,
        private readonly ArtifactReviewManager $reviews,
        private readonly ArtifactStagingManager $staging,
        private readonly StagingIntegrityVerifier $verifier,
        private readonly AddonLivePathResolver $resolver,
        private readonly AddonEventLogger $events,
    ) {}

    public function promote(string $code, ArtifactReviewActor $actor): ArtifactPromotionResult
    {
        $state = $this->resolve($code);
        if ($state === null) {
            return ArtifactPromotionResult::failure($code, null, ArtifactPromotionStatus::BLOCKED, 'Artifact не знайдено.', ['Artifact не знайдено у quarantine.']);
        }

        $reasons = $this->getPromotionBlockedReasons($code);
        if ($reasons !== []) {
            $diagnostics = $this->stagingDiagnostics($state);
            $data = ['metadata' => $state['metadata']];
            if ($diagnostics !== []) {
                $data['diagnostics'] = $diagnostics;
            }

            return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::BLOCKED, 'Promotion заблоковано.', $reasons, $data);
        }

        $preflight = $this->evaluatePromotionIdentity($state);
        if (($preflight['same_artifact'] ?? false) === true && ($preflight['identical'] ?? false) === true) {
            return ArtifactPromotionResult::success($code, $state['version'], ArtifactPromotionStatus::PROMOTED, 'Artifact уже перенесено у live addon directory.', [
                'addon_type' => $state['type'],
                'live_path' => $preflight['live_path'] ?? null,
                'backup_path' => $preflight['backup_path'] ?? null,
                'transaction_id' => $preflight['transaction_id'] ?? null,
                'rollback_available' => (bool) ($preflight['rollback_available'] ?? false),
                'idempotent' => true,
                'inventory_hash' => $preflight['live_inventory_hash'] ?? null,
                'metadata' => $this->refreshMetadata($state),
            ]);
        }

        if (($preflight['same_artifact'] ?? false) === true && ($preflight['live_fingerprint_mismatch'] ?? false) === true) {
            return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::STALE, 'Live fingerprint не збігається з останнім promotion.', [], [
                'metadata' => $this->refreshMetadata($state),
                'diagnostics' => [$this->diagnostic('artifact_promotion_live_fingerprint_mismatch', 'Live tree does not match promoted inventory.', [
                    'live_path='.((string) ($preflight['live_path'] ?? '')),
                    'expected_inventory_hash='.((string) ($preflight['promotion_inventory_hash'] ?? '')),
                    'actual_inventory_hash='.((string) ($preflight['live_inventory_hash'] ?? '')),
                ])],
            ]);
        }

        $lock = Cache::lock($this->lockKey($code), (int) Config::get('addons-registry.promotion.lock_timeout', 30));
        if (! $lock->get()) {
            return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::BLOCKED, 'Promotion lock зайнято.', ['Інша promotion transaction уже виконується.'], ['metadata' => $state['metadata']]);
        }

        $transactionId = (string) Str::uuid();
        $journalPath = $this->journalPath($code, $transactionId);

        try {
            $verification = $this->verifier->verify($this->absoluteStagingPath($state));
            if (! $verification['success']) {
                $this->markPromotionStale($state, $verification['diagnostics']);
                $this->journalStarted($journalPath, $state, $transactionId, $actor, 'promote', null, null);
                $this->journalFailed($journalPath, $verification['diagnostics']);

                return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::STALE, 'Staging не пройшов повторну перевірку.', $this->diagnosticMessages($verification['diagnostics']), [
                    'transaction_id' => $transactionId,
                    'metadata' => $this->refreshMetadata($state),
                    'diagnostics' => $verification['diagnostics'],
                ]);
            }

            $manifest = $verification['manifest'];
            $live = $this->resolver->resolve($manifest);
            $promotion = $this->buildPromotionMetadata($state, $verification, $live, $transactionId, $actor);
            $this->journalStarted($journalPath, $state, $transactionId, $actor, 'promote', $live['live_path'], null);

            if (is_dir($live['live_path']) && ! $this->isExistingLiveAddOnCompatible($live['live_path'], $manifest)) {
                $this->journalFailed($journalPath, ['Existing live directory is foreign or corrupted.']);

                return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::BLOCKED, 'Destination already contains foreign files.', ['Existing live directory is not a compatible addon.'], [
                    'transaction_id' => $transactionId,
                    'metadata' => $this->refreshMetadata($state),
                ]);
            }

            $backupInfo = null;
            if (is_dir($live['live_path']) && (bool) Config::get('addons-registry.promotion.backup_enabled', true)) {
                $backupInfo = $this->backupLive($state, $live['live_path'], $transactionId, $actor);
                if ($backupInfo === null) {
                    $this->journalFailed($journalPath, ['Backup failed.']);

                    return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::FAILED, 'Backup не вдалося створити.', ['Поточний live addon не було змінено.'], [
                        'transaction_id' => $transactionId,
                        'metadata' => $this->refreshMetadata($state),
                    ]);
                }
            }

            $candidate = $this->candidatePath($live['live_path'], $transactionId);
            $this->deleteDirectory($candidate);
            $this->copyTree($verification['payload_path'], $candidate);

            $candidateInventory = $this->inventoryForPath($candidate);
            if ($candidateInventory['inventory_hash'] !== $verification['inventory_hash']) {
                $this->deleteDirectory($candidate);
                $this->journalFailed($journalPath, ['Candidate inventory mismatch.']);

                return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::FAILED, 'Candidate inventory не збігається зі staging.', ['Candidate tree changed during promotion.'], [
                    'transaction_id' => $transactionId,
                    'backup_path' => $backupInfo['backup_path'] ?? null,
                    'metadata' => $this->refreshMetadata($state),
                ]);
            }

            $rollbackTemp = null;
            if (is_dir($live['live_path'])) {
                $rollbackTemp = $this->rollbackTempPath($live['live_path'], $transactionId);
                $this->deleteDirectory($rollbackTemp);
                if (! rename($live['live_path'], $rollbackTemp)) {
                    $this->deleteDirectory($candidate);
                    $this->journalFailed($journalPath, ['Could not move existing live directory to rollback temp.']);

                    return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::FAILED, 'Поточний live directory не вдалося перемістити у rollback temp.', ['Live directory move failed.'], [
                        'transaction_id' => $transactionId,
                        'backup_path' => $backupInfo['backup_path'] ?? null,
                        'metadata' => $this->refreshMetadata($state),
                    ]);
                }
            }

            if (! rename($candidate, $live['live_path'])) {
                if ($rollbackTemp !== null && is_dir($rollbackTemp)) {
                    rename($rollbackTemp, $live['live_path']);
                }
                $this->deleteDirectory($candidate);
                $this->journalFailed($journalPath, ['Could not move candidate to live destination.']);

                return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::FAILED, 'Candidate не вдалося перенести у live destination.', ['Live rename failed.'], [
                    'transaction_id' => $transactionId,
                    'backup_path' => $backupInfo['backup_path'] ?? null,
                    'metadata' => $this->refreshMetadata($state),
                ]);
            }

            if ($rollbackTemp !== null && is_dir($rollbackTemp)) {
                $this->deleteDirectory($rollbackTemp);
            }

            $liveInventory = $this->inventoryForPath($live['live_path']);
            if ($liveInventory['inventory_hash'] !== $verification['inventory_hash']) {
                $this->journalFailed($journalPath, ['Live inventory verification failed.']);

                return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::FAILED, 'Live inventory не збігається після promotion.', ['Live tree verification failed.'], [
                    'transaction_id' => $transactionId,
                    'backup_path' => $backupInfo['backup_path'] ?? null,
                    'metadata' => $this->refreshMetadata($state),
                ]);
            }

            $promotion['promotion_status'] = ArtifactPromotionStatus::PROMOTED;
            $promotion['promotion_transaction_id'] = $transactionId;
            $promotion['promotion_live_path'] = $live['live_path'];
            $promotion['promotion_backup_path'] = $backupInfo['backup_path'] ?? null;
            $promotion['promoted_at'] = now()->toIso8601String();
            $promotion['promoted_by'] = $actor->id;
            $promotion['promoted_by_name'] = $actor->name;
            $promotion['promoted_by_type'] = $actor->type;
            $promotion['promoted_version'] = $state['version'];
            $promotion['promotion_source_artifact_sha256'] = $state['metadata']['sha256'] ?? null;
            $promotion['promotion_inventory_hash'] = $verification['inventory_hash'];
            $promotion['promotion_diagnostics'] = [];
            $promotion['promotion_is_stale'] = false;
            $promotion['rollback_available'] = true;
            $promotion['last_rollback_transaction_id'] = null;

            $this->persistMetadata($state['metadata_path'], $promotion);
            $this->journalCompleted($journalPath, []);
            $this->retention($code);
            $this->logEvent($code, 'marketplace_artifact_promoted', 'Artifact promoted into live addon directory.', [
                'transaction_id' => $transactionId,
                'live_path' => $live['live_path'],
                'backup_path' => $backupInfo['backup_path'] ?? null,
                'version' => $state['version'],
                'type' => $live['type'],
            ]);

            return ArtifactPromotionResult::success($code, $state['version'], ArtifactPromotionStatus::PROMOTED, 'Файли перенесено у live addon directory. Addon ще не discovered/installed/enabled.', [
                'addon_type' => $live['type'],
                'live_path' => $live['live_path'],
                'backup_path' => $backupInfo['backup_path'] ?? null,
                'transaction_id' => $transactionId,
                'rollback_available' => true,
                'metadata' => $promotion,
                'idempotent' => false,
                'inventory_hash' => $verification['inventory_hash'],
            ]);
        } catch (\Throwable $exception) {
            $this->journalFailed($journalPath, [$exception->getMessage()]);
            $this->logEvent($code, 'marketplace_artifact_promotion_failed', 'Artifact promotion failed.', [
                'transaction_id' => $transactionId,
                'error' => $exception->getMessage(),
            ]);

            return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::FAILED, 'Promotion не виконано.', [$exception->getMessage()], [
                'transaction_id' => $transactionId,
                'metadata' => $this->refreshMetadata($state),
            ]);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    public function rollback(string $code, ?string $transactionId, ?string $note, ArtifactReviewActor $actor): ArtifactPromotionResult
    {
        $state = $this->resolve($code);
        if ($state === null) {
            return ArtifactPromotionResult::failure($code, null, ArtifactPromotionStatus::BLOCKED, 'Artifact не знайдено.', ['Artifact не знайдено у quarantine.']);
        }

        $promotion = $this->promotionMetadata($state);
        if ($promotion === null || (string) ($promotion['promotion_status'] ?? '') !== ArtifactPromotionStatus::PROMOTED) {
            return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::BLOCKED, 'Rollback недоступний.', ['Promotion metadata відсутні або rollback недоступний.'], ['metadata' => $state['metadata']]);
        }

        $transactionId = $transactionId ?: (string) ($promotion['promotion_transaction_id'] ?? '');
        if ($transactionId === '') {
            return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::BLOCKED, 'Transaction ID обов’язковий.', ['Promotion transaction id не визначено.']);
        }

        $lock = Cache::lock($this->lockKey($code), (int) Config::get('addons-registry.promotion.lock_timeout', 30));
        if (! $lock->get()) {
            return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::BLOCKED, 'Rollback lock зайнято.', ['Інша promotion/rollback transaction уже виконується.']);
        }

        $journalPath = $this->journalPath($code, $transactionId, 'rollback');
        $currentLive = (string) ($promotion['promotion_live_path'] ?? '');
        $backupPath = (string) ($promotion['promotion_backup_path'] ?? '');

        try {
            $this->journalStarted($journalPath, $state, $transactionId, $actor, 'rollback', $currentLive, $backupPath);

            if ($backupPath !== '' && is_dir($backupPath) && file_exists($backupPath.'/backup.json')) {
                $backup = $this->readJson($backupPath.'/backup.json');
                if (! is_array($backup)) {
                    $this->journalFailed($journalPath, ['Backup manifest invalid.']);

                    return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::FAILED, 'Backup metadata невалідні.', ['Backup metadata malformed.']);
                }

                $candidate = $this->candidatePath($currentLive, 'rollback-'.$transactionId);
                $this->deleteDirectory($candidate);
                $this->copyTree($backupPath.'/payload', $candidate);
                $candidateInventory = $this->inventoryForPath($candidate);

                if ($candidateInventory['inventory_hash'] !== (string) ($backup['live_inventory_hash'] ?? '')) {
                    $this->deleteDirectory($candidate);
                    $this->journalFailed($journalPath, ['Backup inventory mismatch.']);

                    return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::FAILED, 'Backup inventory не збігається.', ['Backup inventory mismatch.']);
                }

                $rollbackTemp = $this->rollbackTempPath($currentLive, 'rollback-'.$transactionId);
                if (is_dir($currentLive) && ! rename($currentLive, $rollbackTemp)) {
                    $this->deleteDirectory($candidate);
                    $this->journalFailed($journalPath, ['Could not move current live to rollback temp.']);

                    return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::ROLLBACK_FAILED, 'Не вдалося перемістити current live у rollback temp.', ['Current live move failed.']);
                }

                if (! rename($candidate, $currentLive)) {
                    if (is_dir($rollbackTemp)) {
                        rename($rollbackTemp, $currentLive);
                    }
                    $this->deleteDirectory($candidate);
                    $this->journalFailed($journalPath, ['Could not restore backup candidate to live.']);

                    return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::ROLLBACK_FAILED, 'Не вдалося відновити live з backup.', ['Live restore failed.']);
                }

                if (is_dir($rollbackTemp)) {
                    $this->deleteDirectory($rollbackTemp);
                }

                $restoredInventory = $this->inventoryForPath($currentLive);
                if ($restoredInventory['inventory_hash'] !== (string) ($backup['live_inventory_hash'] ?? '')) {
                    $this->journalFailed($journalPath, ['Restored live inventory mismatch.']);

                    return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::ROLLBACK_FAILED, 'Відновлений live inventory не збігається.', ['Restored live verification failed.']);
                }

                $promotion['promotion_status'] = ArtifactPromotionStatus::ROLLED_BACK;
                $promotion['rollback_available'] = false;
                $promotion['last_rollback_transaction_id'] = $transactionId;
                $promotion['promotion_diagnostics'] = [];
                $promotion['promotion_is_stale'] = false;
                $this->persistMetadata($state['metadata_path'], $promotion);
                $this->journalCompleted($journalPath, []);
                $this->logEvent($code, 'marketplace_artifact_rolled_back', 'Artifact promotion rolled back.', [
                    'transaction_id' => $transactionId,
                    'live_path' => $currentLive,
                    'backup_path' => $backupPath,
                    'note' => $note,
                ]);

                return ArtifactPromotionResult::success($code, $state['version'], ArtifactPromotionStatus::ROLLED_BACK, 'Promotion rollback виконано.', [
                    'addon_type' => $promotion['addon_type'] ?? null,
                    'live_path' => $currentLive,
                    'backup_path' => $backupPath,
                    'transaction_id' => $transactionId,
                    'rollback_available' => false,
                    'metadata' => $promotion,
                ]);
            }

            if (! is_dir($currentLive)) {
                $this->journalFailed($journalPath, ['Live directory missing for first-install rollback.']);

                return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::BLOCKED, 'Live directory відсутня для rollback.', ['Live directory missing.']);
            }

            $currentInventory = $this->inventoryForPath($currentLive);
            if ($currentInventory['inventory_hash'] !== (string) ($promotion['promotion_inventory_hash'] ?? '')) {
                $this->journalFailed($journalPath, ['Live tree was modified after promotion.']);

                return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::BLOCKED, 'Live tree було змінено вручну; rollback заблоковано.', ['Current live inventory does not match promotion inventory.']);
            }

            $rollbackTemp = $this->rollbackTempPath($currentLive, 'rollback-'.$transactionId);
            $this->deleteDirectory($rollbackTemp);
            if (! rename($currentLive, $rollbackTemp)) {
                $this->journalFailed($journalPath, ['Could not move live tree to rollback temp.']);

                return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::ROLLBACK_FAILED, 'Не вдалося перемістити live tree у rollback temp.', ['Live move failed.']);
            }

            $this->deleteDirectory($rollbackTemp);
            $promotion['promotion_status'] = ArtifactPromotionStatus::ROLLED_BACK;
            $promotion['rollback_available'] = false;
            $promotion['last_rollback_transaction_id'] = $transactionId;
            $promotion['promotion_diagnostics'] = [];
            $promotion['promotion_is_stale'] = false;
            $this->persistMetadata($state['metadata_path'], $promotion);
            $this->journalCompleted($journalPath, []);
            $this->logEvent($code, 'marketplace_artifact_rolled_back', 'Artifact promotion rolled back for first install.', [
                'transaction_id' => $transactionId,
                'live_path' => $currentLive,
                'note' => $note,
            ]);

            return ArtifactPromotionResult::success($code, $state['version'], ArtifactPromotionStatus::ROLLED_BACK, 'First-install promotion rollback виконано.', [
                'addon_type' => $promotion['addon_type'] ?? null,
                'live_path' => $currentLive,
                'backup_path' => null,
                'transaction_id' => $transactionId,
                'rollback_available' => false,
                'metadata' => $promotion,
            ]);
        } catch (\Throwable $exception) {
            $this->journalFailed($journalPath, [$exception->getMessage()]);
            $this->logEvent($code, 'marketplace_artifact_rollback_failed', 'Artifact rollback failed.', [
                'transaction_id' => $transactionId,
                'error' => $exception->getMessage(),
            ]);

            return ArtifactPromotionResult::failure($code, $state['version'], ArtifactPromotionStatus::ROLLBACK_FAILED, 'Rollback не виконано.', [$exception->getMessage()], [
                'transaction_id' => $transactionId,
                'metadata' => $this->refreshMetadata($state),
            ]);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    public function getPromotionReport(string $code): array
    {
        $state = $this->resolve($code);
        if ($state === null) {
            return ['success' => false, 'code' => $code, 'status' => ArtifactPromotionStatus::NOT_PROMOTED, 'diagnostics' => ['Artifact не знайдено у quarantine.']];
        }

        $promotion = $this->promotionMetadata($state) ?? [];
        $stagingPath = $this->absoluteStagingPath($state, false);
        $stagingVerification = $stagingPath !== null
            ? $this->verifier->verify($stagingPath)
            : ['success' => false, 'diagnostics' => [$this->diagnostic('artifact_staging_metadata_invalid', 'Staging path is missing.', ['staging_path missing'])], 'staging_is_stale' => false];
        $identity = $this->evaluatePromotionIdentity($state, $promotion, $stagingVerification);
        $stale = ! $stagingVerification['success']
            || ((string) ($promotion['promotion_status'] ?? '') === ArtifactPromotionStatus::PROMOTED && (bool) ($stagingVerification['staging_is_stale'] ?? false))
            || (bool) ($identity['live_fingerprint_mismatch'] ?? false);
        $promotion['promotion_is_stale'] = $stale;
        if ($promotion !== []) {
            $this->persistMetadata($state['metadata_path'], $promotion);
        }

        $status = (string) ($promotion['promotion_status'] ?? ArtifactPromotionStatus::NOT_PROMOTED);
        if ($status === ArtifactPromotionStatus::NOT_PROMOTED && $this->getPromotionBlockedReasons($code) === []) {
            $status = ArtifactPromotionStatus::READY;
        }

        return [
            'success' => true,
            'code' => $code,
            'version' => $state['version'],
            'status' => $status,
            'addon_type' => $promotion['addon_type'] ?? null,
            'live_path' => $promotion['promotion_live_path'] ?? null,
            'backup_path' => $promotion['promotion_backup_path'] ?? null,
            'transaction_id' => $promotion['promotion_transaction_id'] ?? null,
            'stale' => $stale,
            'rollback_available' => (bool) ($promotion['rollback_available'] ?? false),
            'promoted_at' => $promotion['promoted_at'] ?? null,
            'promoted_by' => $promotion['promoted_by'] ?? null,
            'promoted_by_name' => $promotion['promoted_by_name'] ?? null,
            'promoted_by_type' => $promotion['promoted_by_type'] ?? null,
            'promoted_version' => $promotion['promoted_version'] ?? null,
            'promotion_inventory_hash' => $promotion['promotion_inventory_hash'] ?? null,
            'promotion_diagnostics' => $promotion['promotion_diagnostics'] ?? [],
            'promotion_is_stale' => $stale,
            'idempotent_ready' => $identity['identical'] ?? false,
            'live_inventory_matches' => $identity['live_inventory_matches'] ?? false,
            'live_fingerprint_mismatch' => $identity['live_fingerprint_mismatch'] ?? false,
            'last_promotion_transaction' => $promotion['promotion_transaction_id'] ?? null,
            'live_inventory_hash' => $identity['live_inventory_hash'] ?? null,
            'metadata' => $promotion,
            'diagnostics' => array_values($this->uniqueDiagnostics(array_merge(
                is_array($stagingVerification['diagnostics'] ?? null) ? $stagingVerification['diagnostics'] : [],
                $identity['diagnostics'] ?? [],
            ))),
        ];
    }

    public function canPromote(string $code): bool
    {
        return $this->getPromotionBlockedReasons($code) === [];
    }

    public function canRollback(string $code): bool
    {
        $state = $this->resolve($code);
        if ($state === null) {
            return false;
        }

        $promotion = $this->promotionMetadata($state);

        return is_array($promotion) && (bool) ($promotion['rollback_available'] ?? false);
    }

    public function getPromotionBlockedReasons(string $code): array
    {
        $state = $this->resolve($code);
        if ($state === null) {
            return ['Artifact не знайдено у quarantine.'];
        }

        $promotionConfig = Config::get('addons-registry.promotion', []);
        $reasons = [];

        if (! (bool) ($promotionConfig['enabled'] ?? false)) {
            $reasons[] = 'Promotion вимкнено.';
        }

        if (($state['review']['trust_status'] ?? null) !== 'trusted' && (bool) ($promotionConfig['require_trusted'] ?? true)) {
            $reasons[] = 'Artifact не trusted.';
        }

        if (($state['review']['review_status'] ?? null) !== ArtifactReviewStatus::APPROVED && (bool) ($promotionConfig['require_approved'] ?? true)) {
            $reasons[] = 'Artifact не approved.';
        }

        if ((bool) ($promotionConfig['block_stale_approval'] ?? true) && (bool) ($state['review']['approval_is_stale'] ?? false)) {
            $reasons[] = 'Approval stale.';
        }

        if ((bool) ($promotionConfig['require_staged'] ?? true) && ($state['staging']['staging_status'] ?? null) !== ArtifactStagingStatus::STAGED) {
            $reasons[] = 'Artifact не staged.';
        }

        if ((bool) ($promotionConfig['block_stale_staging'] ?? true) && (bool) ($state['staging']['staging_is_stale'] ?? false)) {
            $reasons[] = 'Staging stale.';
        }

        $stagingPath = $this->absoluteStagingPath($state, false);
        if ($stagingPath === null) {
            $reasons[] = 'Staging path відсутній.';
        } else {
            $verification = $this->verifier->verify($stagingPath);
            if (! $verification['success']) {
                $reasons[] = 'Staging integrity validation failed.';
            }
        }

        return $reasons;
    }

    public function isPromotionStale(string $code): bool
    {
        $report = $this->getPromotionReport($code);

        return (bool) ($report['promotion_is_stale'] ?? false) || (bool) ($report['stale'] ?? false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve(string $code): ?array
    {
        $item = collect($this->catalog->load()['items'] ?? [])->first(fn ($entry) => $entry->code === $code);
        if ($item === null || ! is_array($item->raw['artifact'] ?? null)) {
            return null;
        }

        $artifact = $item->raw['artifact'];
        $disk = (string) Config::get('addons-registry.downloads.disk', 'addons');
        $dir = trim((string) Config::get('addons-registry.downloads.quarantine_path', 'addons/quarantine'), '/').'/'.$code.'/'.$item->version;
        $path = $dir.'/'.basename(parse_url($artifact['url'], PHP_URL_PATH) ?: $code.'.zip');
        $metadataPath = $dir.'/metadata.json';
        $storage = Storage::disk($disk);

        if (! $storage->exists($path) || ! $storage->exists($metadataPath)) {
            return null;
        }

        $metadata = $this->readJson($storage->path($metadataPath)) ?? [];
        $reviewResult = $this->reviews->getReviewReport($code);
        if (! $reviewResult['success']) {
            return null;
        }

        $stagingResult = $this->staging->getStagingReport($code);

        return [
            'code' => $code,
            'version' => $item->version,
            'type' => $item->type,
            'vendor' => $item->vendor,
            'disk' => $disk,
            'artifact_path' => $path,
            'metadata_path' => $metadataPath,
            'metadata' => $metadata,
            'review' => $reviewResult['report'],
            'staging' => $stagingResult,
        ];
    }

    private function absoluteStagingPath(array $state, bool $throwOnMissing = true): ?string
    {
        $stagingPath = $state['staging']['staging_path'] ?? null;
        if (! is_string($stagingPath) || $stagingPath === '') {
            if ($throwOnMissing) {
                throw new RuntimeException('Staging path is missing.');
            }

            return null;
        }

        $disk = (string) ($state['disk'] ?? Config::get('addons-registry.downloads.disk', 'addons'));

        return Storage::disk($disk)->path($stagingPath);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private function promotionMetadata(array $state): ?array
    {
        $metadata = $state['metadata'] ?? [];

        return is_array($metadata) && array_key_exists('promotion_status', $metadata) ? $metadata : null;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function buildPromotionMetadata(array $state, array $verification, array $live, string $transactionId, ArtifactReviewActor $actor): array
    {
        $metadata = $state['metadata'];
        $metadata['promotion_status'] = ArtifactPromotionStatus::PROMOTING;
        $metadata['promotion_transaction_id'] = $transactionId;
        $metadata['promotion_live_path'] = $live['live_path'];
        $metadata['promotion_backup_path'] = null;
        $metadata['promoted_at'] = null;
        $metadata['promoted_by'] = null;
        $metadata['promoted_by_name'] = null;
        $metadata['promoted_by_type'] = null;
        $metadata['promoted_version'] = null;
        $metadata['promotion_inventory_hash'] = $verification['inventory_hash'];
        $metadata['promotion_diagnostics'] = [];
        $metadata['promotion_is_stale'] = false;
        $metadata['rollback_available'] = false;
        $metadata['last_rollback_transaction_id'] = $metadata['last_rollback_transaction_id'] ?? null;

        $this->persistMetadata($state['metadata_path'], $metadata);

        return $metadata;
    }

    private function journalStarted(string $path, array $state, string $transactionId, ArtifactReviewActor $actor, string $action, ?string $livePath, ?string $backupPath): void
    {
        $journal = [
            'schema_version' => 1,
            'transaction_id' => $transactionId,
            'action' => $action,
            'status' => 'started',
            'code' => $state['code'],
            'version' => $state['version'],
            'addon_type' => $state['type'],
            'live_path' => $livePath,
            'staging_path' => $state['staging']['staging_path'] ?? null,
            'backup_path' => $backupPath,
            'started_at' => now()->toIso8601String(),
            'started_by' => $actor->id,
            'started_by_name' => $actor->name,
            'started_by_type' => $actor->type,
            'steps' => [],
            'diagnostics' => [],
        ];

        $this->writeJson($path, $journal);
    }

    private function journalCompleted(string $path, array $diagnostics): void
    {
        $journal = $this->readJson($path) ?? [];
        $journal['status'] = 'completed';
        $journal['completed_at'] = now()->toIso8601String();
        $journal['diagnostics'] = $diagnostics;
        $journal['steps'][] = [
            'step' => 'completed',
            'status' => 'completed',
            'created_at' => now()->toIso8601String(),
            'details' => [],
        ];

        $this->writeJson($path, $journal);
    }

    private function journalFailed(string $path, array $diagnostics): void
    {
        $journal = $this->readJson($path) ?? [];
        $journal['status'] = 'failed';
        $journal['failed_at'] = now()->toIso8601String();
        $journal['diagnostics'] = array_values(array_unique(array_merge($journal['diagnostics'] ?? [], $diagnostics)));
        $journal['steps'][] = [
            'step' => 'failed',
            'status' => 'failed',
            'created_at' => now()->toIso8601String(),
            'details' => ['diagnostics' => $diagnostics],
        ];

        $this->writeJson($path, $journal);
    }

    /**
     * @return array{backup_path: string}|null
     */
    private function backupLive(array $state, string $livePath, string $transactionId, ArtifactReviewActor $actor): ?array
    {
        $backupDisk = Storage::disk((string) Config::get('addons-registry.promotion.backup_disk', 'addons'));
        $backupRoot = rtrim((string) Config::get('addons-registry.promotion.backup_path', 'addons/backups'), '/');
        $timestamp = now()->format('YmdHis');
        $backupPath = $backupRoot.'/'.$state['code'].'/'.$timestamp.'-'.$transactionId;

        $this->deleteDirectory($backupDisk->path($backupPath));
        $payloadPath = $backupDisk->path($backupPath.'/payload');

        try {
            $this->copyTree($livePath, $payloadPath);
        } catch (\Throwable) {
            $this->deleteDirectory($backupDisk->path($backupPath));

            return null;
        }

        $inventory = $this->inventoryForPath($payloadPath);
        $backup = [
            'transaction_id' => $transactionId,
            'code' => $state['code'],
            'old_version' => $this->liveManifest($livePath)['version'] ?? null,
            'old_manifest' => $this->liveManifest($livePath),
            'file_inventory' => $inventory['inventory'],
            'live_inventory_hash' => $inventory['inventory_hash'],
            'created_at' => now()->toIso8601String(),
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
            'created_by_type' => $actor->type,
            'source_live_path' => $livePath,
        ];
        $this->writeJson($backupDisk->path($backupPath.'/backup.json'), $backup);

        return ['backup_path' => $backupDisk->path($backupPath)];
    }

    private function retention(string $code): void
    {
        $keep = max(1, (int) Config::get('addons-registry.promotion.keep_backups', 5));
        $disk = Storage::disk((string) Config::get('addons-registry.promotion.backup_disk', 'addons'));
        $root = rtrim((string) Config::get('addons-registry.promotion.backup_path', 'addons/backups'), '/').'/'.$code;

        if (! $disk->exists($root)) {
            return;
        }

        $directories = collect($disk->directories($root))
            ->map(fn (string $directory): array => ['path' => $directory, 'mtime' => $disk->lastModified($directory)])
            ->sortBy('mtime')
            ->values();

        while ($directories->count() > $keep) {
            $oldest = $directories->shift();
            if (! is_array($oldest)) {
                continue;
            }

            $this->deleteDirectory($disk->path((string) $oldest['path']));
        }
    }

    private function markPromotionStale(array $state, array $diagnostics): void
    {
        $metadata = $state['metadata'];
        $metadata['promotion_status'] = ArtifactPromotionStatus::STALE;
        $metadata['promotion_is_stale'] = true;
        $metadata['promotion_diagnostics'] = array_values(array_unique(array_merge($metadata['promotion_diagnostics'] ?? [], $diagnostics)));
        $this->persistMetadata($state['metadata_path'], $metadata);
    }

    private function refreshMetadata(array $state): array
    {
        return $this->readJson(Storage::disk($state['disk'])->path($state['metadata_path'])) ?? $state['metadata'];
    }

    private function persistMetadata(string $metadataPath, array $metadata): void
    {
        $disk = Storage::disk((string) Config::get('addons-registry.downloads.disk', 'addons'));
        $existing = $disk->exists($metadataPath) ? json_decode($disk->get($metadataPath), true) : [];
        $existing = is_array($existing) ? $existing : [];

        $disk->put(
            $metadataPath,
            json_encode(array_replace($existing, $metadata), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    private function lockKey(string $code): string
    {
        return 'addons:promotion:'.$code;
    }

    private function journalPath(string $code, string $transactionId, string $action = 'promote'): string
    {
        $root = rtrim((string) Config::get('addons-registry.promotion.journal_path', 'addons/promotion-journal'), '/');

        return Storage::disk((string) Config::get('addons-registry.promotion.journal_disk', 'addons'))->path($root.'/'.$code.'/'.$transactionId.($action === 'rollback' ? '.rollback' : '').'.json');
    }

    private function candidatePath(string $livePath, string $transactionId): string
    {
        $parent = dirname($livePath);
        $name = basename($livePath);

        return $parent.'/.'.$name.'.promote-'.$transactionId;
    }

    private function rollbackTempPath(string $livePath, string $transactionId): string
    {
        $parent = dirname($livePath);
        $name = basename($livePath);

        return $parent.'/.'.$name.'.rollback-'.$transactionId;
    }

    private function isExistingLiveAddOnCompatible(string $livePath, array $manifest): bool
    {
        $liveManifest = $this->liveManifest($livePath);

        if ($liveManifest === []) {
            return false;
        }

        return (string) ($liveManifest['code'] ?? '') === (string) ($manifest['code'] ?? '')
            && (string) ($liveManifest['type'] ?? '') === (string) ($manifest['type'] ?? '')
            && (string) ($liveManifest['vendor'] ?? '') === (string) ($manifest['vendor'] ?? '');
    }

    private function liveManifest(string $livePath): array
    {
        foreach (['manifest.json', 'module.json', 'extension.json'] as $candidate) {
            $path = $livePath.'/'.$candidate;
            if (is_file($path)) {
                $decoded = $this->readJson($path);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<int, array{code: string, message: string, details?: array<int, string>}>  $diagnostics
     * @return array<int, string>
     */
    private function diagnosticMessages(array $diagnostics): array
    {
        $messages = [];

        foreach ($diagnostics as $diagnostic) {
            if (! is_array($diagnostic)) {
                continue;
            }

            $messages[] = (string) ($diagnostic['code'] ?? 'diagnostic').': '.(string) ($diagnostic['message'] ?? '');
        }

        return array_values(array_filter(array_unique($messages), static fn (string $message): bool => $message !== ''));
    }

    /**
     * @param  array<int, array{code: string, message: string, details?: array<int, string>}>  $diagnostics
     * @return array<int, array{code: string, message: string, details: array<int, string>}>
     */
    private function uniqueDiagnostics(array $diagnostics): array
    {
        $unique = [];

        foreach ($diagnostics as $diagnostic) {
            if (! is_array($diagnostic) || ! isset($diagnostic['code'], $diagnostic['message'])) {
                continue;
            }

            $key = $diagnostic['code'].'|'.$diagnostic['message'].'|'.json_encode($diagnostic['details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $unique[$key] = [
                'code' => (string) $diagnostic['code'],
                'message' => (string) $diagnostic['message'],
                'details' => array_values(array_map('strval', (array) ($diagnostic['details'] ?? []))),
            ];
        }

        return array_values($unique);
    }

    /**
     * @return array{code: string, message: string, details: array<int, string>}
     */
    private function diagnostic(string $code, string $message, array $details = []): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'details' => array_values(array_map('strval', $details)),
        ];
    }

    /**
     * @return array<int, array{code: string, message: string, details: array<int, string>}>
     */
    private function stagingDiagnostics(array $state): array
    {
        $stagingPath = $this->absoluteStagingPath($state, false);

        if ($stagingPath === null) {
            return [$this->diagnostic('artifact_staging_metadata_invalid', 'Staging path is missing.', ['staging_path missing'])];
        }

        $verification = $this->verifier->verify($stagingPath);

        return array_values($this->uniqueDiagnostics(is_array($verification['diagnostics'] ?? null) ? $verification['diagnostics'] : []));
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>|null  $promotion
     * @param  array<string, mixed>|null  $verification
     * @return array{same_artifact: bool, identical: bool, live_fingerprint_mismatch: bool, live_inventory_matches: bool, live_inventory_hash: ?string, live_path: ?string, backup_path: ?string, transaction_id: ?string, promotion_inventory_hash: ?string, rollback_available: bool, diagnostics: array<int, array{code: string, message: string, details: array<int, string>}>}
     */
    private function evaluatePromotionIdentity(array $state, ?array $promotion = null, ?array $verification = null): array
    {
        $promotion = $promotion ?? $this->promotionMetadata($state);
        if (! is_array($promotion) || (string) ($promotion['promotion_status'] ?? '') !== ArtifactPromotionStatus::PROMOTED) {
            return [
                'same_artifact' => false,
                'identical' => false,
                'live_fingerprint_mismatch' => false,
                'live_inventory_matches' => false,
                'live_inventory_hash' => null,
                'live_path' => null,
                'backup_path' => null,
                'transaction_id' => null,
                'promotion_inventory_hash' => null,
                'rollback_available' => false,
                'diagnostics' => [],
            ];
        }

        $stagingPath = $this->absoluteStagingPath($state, false);
        if ($verification === null) {
            $verification = $stagingPath !== null ? $this->verifier->verify($stagingPath) : null;
        }

        if (! is_array($verification) || ! ($verification['success'] ?? false)) {
            return [
                'same_artifact' => false,
                'identical' => false,
                'live_fingerprint_mismatch' => false,
                'live_inventory_matches' => false,
                'live_inventory_hash' => null,
                'live_path' => $promotion['promotion_live_path'] ?? null,
                'backup_path' => $promotion['promotion_backup_path'] ?? null,
                'transaction_id' => $promotion['promotion_transaction_id'] ?? null,
                'promotion_inventory_hash' => $promotion['promotion_inventory_hash'] ?? null,
                'rollback_available' => (bool) ($promotion['rollback_available'] ?? false),
                'diagnostics' => [],
            ];
        }

        $live = $this->resolver->resolve($verification['manifest']);
        $sameArtifact = $this->promotionSourceMatchesState($state, $promotion, $verification, $live);
        if (! $sameArtifact) {
            return [
                'same_artifact' => false,
                'identical' => false,
                'live_fingerprint_mismatch' => false,
                'live_inventory_matches' => false,
                'live_inventory_hash' => null,
                'live_path' => $live['live_path'] ?? null,
                'backup_path' => $promotion['promotion_backup_path'] ?? null,
                'transaction_id' => $promotion['promotion_transaction_id'] ?? null,
                'promotion_inventory_hash' => $promotion['promotion_inventory_hash'] ?? null,
                'rollback_available' => (bool) ($promotion['rollback_available'] ?? false),
                'diagnostics' => [],
            ];
        }

        $liveInventory = is_dir($live['live_path']) ? $this->inventoryForPath($live['live_path']) : null;
        $liveManifest = is_dir($live['live_path']) ? $this->liveManifest($live['live_path']) : [];
        $liveMatches = is_array($liveInventory)
            && ($liveInventory['inventory_hash'] ?? null) === ($verification['inventory_hash'] ?? null)
            && $this->liveManifestMatches($liveManifest, $verification['manifest']);

        if (! $liveMatches) {
            $actualHash = is_array($liveInventory) ? ($liveInventory['inventory_hash'] ?? null) : null;

            return [
                'same_artifact' => true,
                'identical' => false,
                'live_fingerprint_mismatch' => true,
                'live_inventory_matches' => false,
                'live_inventory_hash' => is_string($actualHash) ? $actualHash : null,
                'live_path' => $live['live_path'] ?? null,
                'backup_path' => $promotion['promotion_backup_path'] ?? null,
                'transaction_id' => $promotion['promotion_transaction_id'] ?? null,
                'promotion_inventory_hash' => $promotion['promotion_inventory_hash'] ?? null,
                'rollback_available' => (bool) ($promotion['rollback_available'] ?? false),
                'diagnostics' => [
                    $this->diagnostic('artifact_promotion_live_fingerprint_mismatch', 'Live tree does not match promoted inventory.', [
                        'live_path='.(string) ($live['live_path'] ?? ''),
                        'expected_inventory_hash='.(string) ($promotion['promotion_inventory_hash'] ?? ''),
                        'actual_inventory_hash='.(string) ($actualHash ?? ''),
                    ]),
                ],
            ];
        }

        return [
            'same_artifact' => true,
            'identical' => true,
            'live_fingerprint_mismatch' => false,
            'live_inventory_matches' => true,
            'live_inventory_hash' => $liveInventory['inventory_hash'] ?? null,
            'live_path' => $live['live_path'] ?? null,
            'backup_path' => $promotion['promotion_backup_path'] ?? null,
            'transaction_id' => $promotion['promotion_transaction_id'] ?? null,
            'promotion_inventory_hash' => $promotion['promotion_inventory_hash'] ?? null,
            'rollback_available' => (bool) ($promotion['rollback_available'] ?? false),
            'diagnostics' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $promotion
     * @param  array<string, mixed>  $verification
     * @param  array<string, mixed>  $live
     */
    private function promotionSourceMatchesState(array $state, array $promotion, array $verification, array $live): bool
    {
        if (($promotion['promotion_live_path'] ?? null) !== ($live['live_path'] ?? null)) {
            return false;
        }

        if (($promotion['promoted_version'] ?? null) !== ($state['version'] ?? null)) {
            return false;
        }

        if (($promotion['promotion_inventory_hash'] ?? null) !== ($verification['inventory_hash'] ?? null)) {
            return false;
        }

        if (($promotion['promotion_source_artifact_sha256'] ?? $state['metadata']['sha256'] ?? null) !== ($state['metadata']['sha256'] ?? null)) {
            return false;
        }

        return $this->liveManifestMatches($this->liveManifest((string) ($live['live_path'] ?? '')), $verification['manifest']);
    }

    /**
     * @param  array<string, mixed>  $liveManifest
     * @param  array<string, mixed>  $expectedManifest
     */
    private function liveManifestMatches(array $liveManifest, array $expectedManifest): bool
    {
        return (string) ($liveManifest['code'] ?? '') === (string) ($expectedManifest['code'] ?? '')
            && (string) ($liveManifest['version'] ?? '') === (string) ($expectedManifest['version'] ?? '')
            && (string) ($liveManifest['type'] ?? '') === (string) ($expectedManifest['type'] ?? '');
    }

    /**
     * @return array{inventory: array<int, array<string, mixed>>, inventory_hash: string, file_count: int, total_size: int}
     */
    private function inventoryForPath(string $path): array
    {
        $inventory = [];
        $fileCount = 0;
        $totalSize = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            $absolute = $entry->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen(rtrim($path, '/\\')))), '/');

            if ($relative === '') {
                continue;
            }

            if ($entry->isLink() || (! $entry->isDir() && ! $entry->isFile())) {
                throw new RuntimeException('Unsafe file type: '.$relative);
            }

            if ($entry->isDir()) {
                continue;
            }

            $fileCount++;
            $size = $entry->getSize() ?: 0;
            $totalSize += $size;
            $inventory[] = ['path' => $relative, 'type' => 'file', 'size' => $size, 'sha256' => hash_file('sha256', $absolute) ?: null];
        }

        usort($inventory, fn (array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path']));

        return [
            'inventory' => $inventory,
            'inventory_hash' => hash('sha256', $this->canonical($inventory)),
            'file_count' => $fileCount,
            'total_size' => $totalSize,
        ];
    }

    private function copyTree(string $source, string $destination): void
    {
        $this->deleteDirectory($destination);

        if (! is_dir($source)) {
            throw new RuntimeException('Source directory does not exist.');
        }

        if (! mkdir($destination, 0755, true) && ! is_dir($destination)) {
            throw new RuntimeException('Could not create destination directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            $absolute = $entry->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen(rtrim($source, '/\\')))), '/');
            $target = $destination.'/'.$relative;

            if ($entry->isLink() || (! $entry->isDir() && ! $entry->isFile())) {
                throw new RuntimeException('Unsafe file type during copy: '.$relative);
            }

            if ($entry->isDir()) {
                if (! is_dir($target) && ! mkdir($target, 0755, true)) {
                    throw new RuntimeException('Failed to create directory: '.$relative);
                }

                continue;
            }

            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true)) {
                throw new RuntimeException('Failed to create parent directory: '.$relative);
            }

            if (! copy($absolute, $target)) {
                throw new RuntimeException('Failed to copy file: '.$relative);
            }

            chmod($target, 0644);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entryPath = $entry->getPathname();
            if ($entry->isDir()) {
                @rmdir($entryPath);

                continue;
            }

            @unlink($entryPath);
        }

        @rmdir($path);
    }

    private function readJson(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function writeJson(string $path, array $data): void
    {
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Could not create directory for JSON file.');
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<int, mixed>  $value
     */
    private function canonical(array $value): string
    {
        if (array_is_list($value)) {
            return json_encode(array_map(fn ($item) => is_array($item) ? json_decode($this->canonical($item), true) : $item, $value), JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = json_decode($this->canonical($item), true);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function logEvent(string $code, string $event, string $message, array $context): void
    {
        if (Schema::hasTable('system_addons') && SystemAddon::where('code', $code)->exists()) {
            $this->events->info($code, $event, $message, $context);
        }
    }
}
