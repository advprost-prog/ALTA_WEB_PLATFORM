<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\RecoveryDataCleanupService;
use Illuminate\Console\Command;

final class BackupsCleanup extends Command
{
    protected $signature = 'addons:backups:cleanup {--dry-run} {--execute} {--addon=} {--backup=}';

    protected $description = 'Dry-run or explicitly execute safe addon backup retention cleanup.';

    public function handle(RecoveryDataCleanupService $cleanup): int
    {
        $items = array_values(array_filter($cleanup->scanBackups(), fn ($i) => ($this->option('addon') === null || $i->addonCode === $this->option('addon')) && ($this->option('backup') === null || $i->backupId === $this->option('backup'))));
        $execute = (bool) $this->option('execute') && ! $this->option('dry-run');
        $failed = false;
        foreach ($items as $item) {
            $result = $execute && $item->eligible ? $cleanup->cleanupBackup($item->backupId, $item->fingerprint) : ['success' => $item->eligible, 'code' => $item->reason];
            $this->line($item->backupId.' '.($execute ? $result['code'] : 'dry-run:'.$result['code']));
            if (($this->option('backup') !== null || $execute) && ! $result['success']) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
