<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\AddonRecoveryHealthService;
use Illuminate\Console\Command;

final class RecoveryHealth extends Command
{
    protected $signature = 'addons:recovery:health {--json} {--refresh}';

    protected $description = 'Read-only cached health projection for addon recovery operations.';

    public function handle(AddonRecoveryHealthService $health): int
    {
        $result = $health->health((bool) $this->option('refresh'));
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Marketplace operations: '.$result['status']);
            $this->table(['Unresolved', 'Automatic', 'Manual', 'Active', 'Corrupt backups', 'Cleanup pending'], [[
                $result['unresolved_count'], $result['automatic_safe_count'], $result['manual_intervention_count'],
                $result['active_operation_count'], $result['corrupt_backup_count'], $result['cleanup_pending_count'],
            ]]);
        }

        return $result['status'] === 'manual_intervention_required' ? self::FAILURE : self::SUCCESS;
    }
}
