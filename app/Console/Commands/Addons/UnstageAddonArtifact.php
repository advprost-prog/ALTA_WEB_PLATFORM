<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\ArtifactReviewActor;
use App\Support\Addons\Registry\ArtifactStagingManager;
use Illuminate\Console\Command;

class UnstageAddonArtifact extends Command
{
    protected $signature = 'addons:unstage-artifact {code} {--note=}';

    protected $description = 'Remove only the staged copy; preserve the quarantine artifact.';

    public function handle(ArtifactStagingManager $manager): int
    {
        $result = $manager->unstage((string) $this->argument('code'), $this->option('note'), ArtifactReviewActor::cli());
        if (! $result->success) {
            $this->error($result->message);

            return self::FAILURE;
        }
        $this->info($result->message);

        return self::SUCCESS;
    }
}
