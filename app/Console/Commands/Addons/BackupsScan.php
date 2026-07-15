<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\RecoveryDataCleanupService;
use Illuminate\Console\Command;

final class BackupsScan extends Command
{
    protected $signature = 'addons:backups:scan {--json}';

    protected $description = 'Read-only scan of managed addon backup retention evidence.';

    public function handle(RecoveryDataCleanupService $cleanup): int
    {
        $rows = array_map(fn ($item) => $item->toArray(), $cleanup->scanBackups());
        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Backup', 'Addon', 'Version', 'Eligible', 'Reason'], array_map(fn ($r) => [$r['backupId'], $r['addonCode'] ?? 'unknown', $r['version'] ?? 'unknown', $r['eligible'] ? 'yes' : 'no', $r['reason']], $rows));
        }

        return self::SUCCESS;
    }
}
