<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\AddonRecoveryService;
use Illuminate\Console\Command;

final class RecoveryScan extends Command
{
    protected $signature = 'addons:recovery:scan {--json}';

    protected $description = 'Read-only scan of incomplete addon install operations.';

    public function handle(AddonRecoveryService $recovery): int
    {
        $rows = array_map(fn ($assessment) => $assessment->toArray(), $recovery->scan());
        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Operation', 'Addon', 'State', 'Classification', 'Automatic'], array_map(fn ($row) => [substr($row['operationId'], 0, 8), $row['code'], $row['journalState'], $row['classification'], $row['automaticEligible'] ? 'yes' : 'no'], $rows));
        }

        return $rows === [] ? self::SUCCESS : self::FAILURE;
    }
}
