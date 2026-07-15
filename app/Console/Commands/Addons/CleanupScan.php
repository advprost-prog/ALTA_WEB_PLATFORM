<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\RecoveryDataCleanupService;
use Illuminate\Console\Command;

final class CleanupScan extends Command
{
    protected $signature = 'addons:cleanup:scan {--json}';

    protected $description = 'Read-only scan of stale managed addon operation remnants.';

    public function handle(RecoveryDataCleanupService $cleanup): int
    {
        $rows = array_map(fn ($item) => $item->toArray(), $cleanup->scanRemnants());
        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Identifier', 'Kind', 'Operation', 'Eligible', 'Reason'], array_map(fn ($r) => [substr($r['identifier'], 0, 12), $r['kind'], $r['operationId'] ? substr($r['operationId'], 0, 8) : 'unknown', $r['eligible'] ? 'yes' : 'no', $r['reason']], $rows));
        }

        return self::SUCCESS;
    }
}
