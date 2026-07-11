<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\ArtifactReviewActor;
use App\Support\Addons\Registry\ArtifactStagingManager;
use Illuminate\Console\Command;

class StageAddonArtifact extends Command
{
    protected $signature = 'addons:stage-artifact {code}';

    protected $description = 'Safely extract a trusted and approved artifact into staging (not install).';

    public function handle(ArtifactStagingManager $manager): int
    {
        $result = $manager->stage((string) $this->argument('code'), ArtifactReviewActor::cli());
        if (! $result->success) {
            $this->error($result->message);
            foreach ($result->blockedReasons as $reason) {
                $this->line('  - '.$reason);
            }

            return self::FAILURE;
        }
        $this->info('Code: '.$result->code);
        $this->line('Version: '.$result->version);
        $this->line('Staging status: '.$result->status);
        $this->line('Staging path: '.$result->stagingPath);
        $this->line('File count: '.$result->fileCount);
        $this->line('Total size: '.$result->totalSize);
        $this->line('Inventory hash: '.($result->metadata['fingerprint']['inventory_hash'] ?? '—'));
        $this->warn('Staging is not installation; no artifact code was executed.');

        return self::SUCCESS;
    }
}
