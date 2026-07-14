<?php

namespace Tests\Unit\Support\Addons\Registry;

use App\Support\Addons\Registry\BackupIntegrityService;
use App\Support\Addons\Registry\ManagedTreeInventory;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BackupIntegrityServiceTest extends TestCase
{
    public function test_record_is_deterministic_and_detects_changed_missing_and_extra_files(): void
    {
        Storage::fake('addons');
        config(['addons-registry.promotion.backup_disk' => 'addons', 'addons-registry.promotion.backup_path' => 'addons/backups']);
        $path = Storage::disk('addons')->path('addons/backups/core.demo/op-1');
        mkdir($path.'/payload', 0755, true);
        file_put_contents($path.'/payload/module.json', json_encode(['code' => 'core.demo', 'version' => '1.0.0', 'type' => 'module', 'vendor' => 'Core']));
        file_put_contents($path.'/payload/readme.txt', 'stable');
        $service = new BackupIntegrityService(new ManagedTreeInventory);
        $record = $service->create($path, ['code' => 'core.demo', 'version' => '1.0.0', 'type' => 'module', 'vendor' => 'Core', 'operation_id' => 'op-1']);

        $this->assertTrue($service->verify($path)['valid']);
        $this->assertSame(2, $record['file_count']);
        $this->assertCount(2, $record['files']);
        file_put_contents($path.'/payload/readme.txt', 'changed');
        $this->assertFalse($service->verify($path)['valid']);
        file_put_contents($path.'/payload/readme.txt', 'stable');
        unlink($path.'/payload/readme.txt');
        $this->assertFalse($service->verify($path)['valid']);
        file_put_contents($path.'/payload/readme.txt', 'stable');
        file_put_contents($path.'/payload/extra.txt', 'extra');
        $this->assertFalse($service->verify($path)['valid']);
    }

    public function test_legacy_and_unmanaged_backups_fail_closed(): void
    {
        Storage::fake('addons');
        config(['addons-registry.promotion.backup_disk' => 'addons', 'addons-registry.promotion.backup_path' => 'addons/backups']);
        $managed = Storage::disk('addons')->path('addons/backups/core.demo/legacy');
        mkdir($managed.'/payload', 0755, true);
        $service = new BackupIntegrityService(new ManagedTreeInventory);

        $this->assertSame('legacy_unverified', $service->verify($managed)['status']);
        $this->assertSame('unmanaged', $service->verify(sys_get_temp_dir().'/outside-backup')['status']);
    }
}
