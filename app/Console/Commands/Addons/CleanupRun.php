<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\RecoveryDataCleanupService;
use Illuminate\Console\Command;

final class CleanupRun extends Command
{
    protected $signature = 'addons:cleanup:run {--dry-run} {--execute} {--operation=} {--kind=}';

    protected $description = 'Dry-run or explicitly execute safe stale addon data cleanup.';

    public function handle(RecoveryDataCleanupService $cleanup): int
    {
        $items = array_values(array_filter($cleanup->scanRemnants(), fn ($i) => ($this->option('operation') === null || $i->operationId === $this->option('operation')) && ($this->option('kind') === null || $i->kind === $this->option('kind'))));
        $execute = (bool) $this->option('execute') && ! $this->option('dry-run');
        $failed = false;
        foreach ($items as $item) {
            $result = $execute && $item->eligible ? $cleanup->cleanupRemnant($item->identifier, $item->fingerprint) : ['success' => $item->eligible, 'code' => $item->reason];
            $this->line(substr($item->identifier, 0, 12).' '.($execute ? $result['code'] : 'dry-run:'.$result['code']));
            if ($execute && ! $result['success']) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
