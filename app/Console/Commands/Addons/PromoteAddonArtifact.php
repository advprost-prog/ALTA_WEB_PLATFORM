<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\ArtifactPromotionManager;
use App\Support\Addons\Registry\ArtifactReviewActor;
use Illuminate\Console\Command;

class PromoteAddonArtifact extends Command
{
    protected $signature = 'addons:promote-artifact {code : Addon code to promote into live directory}';

    protected $description = 'Safely promote a trusted, approved, staged artifact into live addon directory without enabling it.';

    public function handle(ArtifactPromotionManager $manager): int
    {
        $code = (string) $this->argument('code');
        $result = $manager->promote($code, ArtifactReviewActor::cli());

        if (! $result->success) {
            $this->error($result->message);
            foreach ($result->blockedReasons ?: $result->diagnostics as $reason) {
                $this->line('  - '.$reason);
            }

            return self::FAILURE;
        }

        $this->info('Code: '.$result->code);
        $this->line('Version: '.$result->version);
        $this->line('Type: '.$result->addonType);
        $this->line('Live path: '.$result->livePath);
        $this->line('Backup path: '.($result->backupPath ?? '—'));
        $this->line('Transaction ID: '.($result->transactionId ?? '—'));
        $this->line('Promotion status: '.$result->status);
        $this->line('Rollback available: '.($result->rollbackAvailable ? 'yes' : 'no'));
        $this->warn('Addon files are promoted only. Addon is not discovered, installed, or enabled.');

        return self::SUCCESS;
    }
}