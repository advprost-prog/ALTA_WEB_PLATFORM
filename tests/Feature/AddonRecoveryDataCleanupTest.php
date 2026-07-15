<?php

namespace Tests\Feature;

use App\Support\Addons\Registry\BackupIntegrityService;
use App\Support\Addons\Registry\RecoveryDataCleanupService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AddonRecoveryDataCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('addons');
        config([
            'addons-registry.promotion.backup_disk' => 'addons',
            'addons-registry.promotion.backup_path' => 'addons/backups',
            'addons-registry.promotion.journal_disk' => 'addons',
            'addons-registry.downloads.disk' => 'addons',
            'addons-registry.downloads.quarantine_path' => 'addons/quarantine',
            'addons-registry.cleanup.enabled' => true,
            'addons-registry.cleanup.backup_retention_min_count' => 1,
            'addons-registry.cleanup.backup_retention_max_count' => 2,
            'addons-registry.cleanup.backup_retention_days' => 30,
            'addons-registry.cleanup.stale_after' => 60,
            'addons-registry.cleanup.tombstone_path' => 'addons/cleanup-journal/backups',
        ]);
    }

    public function test_retention_is_deterministic_and_cleanup_preserves_tombstone(): void
    {
        $this->backup('old', 'op-old', '1.0.0', '2025-01-01T00:00:00+00:00');
        $this->backup('new', 'op-new', '1.1.0', '2026-01-01T00:00:00+00:00');
        $this->journal('op-old');
        $this->journal('op-new');
        $service = app(RecoveryDataCleanupService::class);

        $first = $service->scanBackups();
        $second = $service->scanBackups();
        $this->assertSame(array_map(fn ($item) => $item->toArray(), $first), array_map(fn ($item) => $item->toArray(), $second));
        $old = collect($first)->firstWhere('backupId', 'old');
        $new = collect($first)->firstWhere('backupId', 'new');
        $this->assertTrue($old->eligible);
        $this->assertSame('eligible_age', $old->reason);
        $this->assertFalse($new->eligible);
        $this->assertTrue($new->lastKnownGood);

        $result = $service->cleanupBackup('old', $old->fingerprint);
        $this->assertTrue($result['success']);
        Storage::disk('addons')->assertMissing('addons/backups/alta.cleanup/old');
        Storage::disk('addons')->assertExists('addons/cleanup-journal/backups/old.json');
        $this->assertSame('deleted', json_decode(Storage::disk('addons')->get('addons/cleanup-journal/backups/old.json'), true)['status']);
    }

    public function test_stale_part_scan_is_read_only_and_exact_cleanup_is_idempotently_rescanned(): void
    {
        $path = Storage::disk('addons')->path('addons/quarantine/alta.cleanup/'.str_repeat('a', 32).'.part');
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, 'partial');
        touch($path, time() - 120);
        $before = hash_file('sha256', $path);
        $service = app(RecoveryDataCleanupService::class);

        $item = $service->scanRemnants()[0];
        $this->assertSame($before, hash_file('sha256', $path));
        $this->assertTrue($item->eligible);
        $this->assertSame('quarantine_part', $item->kind);
        $this->assertTrue($service->cleanupRemnant($item->identifier, $item->fingerprint)['success']);
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], $service->scanRemnants());
    }

    private function backup(string $id, string $operation, string $version, string $created): void
    {
        $path = Storage::disk('addons')->path('addons/backups/alta.cleanup/'.$id);
        mkdir($path.'/payload', 0755, true);
        file_put_contents($path.'/payload/module.json', json_encode(['code' => 'alta.cleanup', 'version' => $version, 'type' => 'module', 'vendor' => 'Alta']));
        app(BackupIntegrityService::class)->create($path, ['code' => 'alta.cleanup', 'version' => $version, 'type' => 'module', 'vendor' => 'Alta', 'operation_id' => $operation]);
        $record = json_decode(file_get_contents($path.'/backup.json'), true);
        $record['created_at'] = $created;
        file_put_contents($path.'/backup.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function journal(string $operation): void
    {
        Storage::disk('addons')->put('addons/install-journal/alta.cleanup/'.$operation.'.json', json_encode([
            'operation_id' => $operation, 'code' => 'alta.cleanup', 'state' => 'completed',
        ]));
    }
}
