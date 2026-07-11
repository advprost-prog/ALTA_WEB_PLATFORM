<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\ArtifactPromotionManager;
use App\Support\Addons\Registry\ArtifactReviewActor;
use Illuminate\Console\Command;

class RollbackAddonPromotion extends Command
{
    protected $signature = 'addons:rollback-artifact {code : Addon code to rollback} {--transaction= : Promotion transaction ID} {--note= : Rollback note}';

    protected $description = 'Rollback a live promoted addon directory without touching quarantine or staging data.';

    public function handle(ArtifactPromotionManager $manager): int
    {
        $code = (string) $this->argument('code');
        $result = $manager->rollback($code, $this->option('transaction') ?: null, $this->option('note') ?: null, ArtifactReviewActor::cli());

        if (! $result->success) {
            $this->error($result->message);
            foreach ($result->blockedReasons ?: $result->diagnostics as $reason) {
                $this->line('  - '.$reason);
            }

            return self::FAILURE;
        }

        $this->info('Code: '.$result->code);
        $this->line('Version: '.$result->version);
        $this->line('Live path: '.($result->livePath ?? '—'));
        $this->line('Transaction ID: '.($result->transactionId ?? '—'));
        $this->line('Rollback status: '.$result->status);
        $this->line('Rollback available: '.($result->rollbackAvailable ? 'yes' : 'no'));
        $this->warn('Quarantine and staging are preserved. Addon is not enabled or discovered automatically.');

        return self::SUCCESS;
    }
}