<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\AddonOperationalRollbackService;
use App\Support\Addons\Registry\ArtifactReviewActor;
use Illuminate\Console\Command;

final class RollbackAddonVersion extends Command
{
    protected $signature = 'addons:rollback-version {code} {--operation=} {--dry-run}';

    protected $description = 'Rollback a completed addon update to its verified retained backup.';

    public function handle(AddonOperationalRollbackService $rollback): int
    {
        $plan = $rollback->assess((string) $this->argument('code'), $this->option('operation') ?: null);
        if ($this->option('dry-run') || ! $plan['success']) {
            $this->line(json_encode($plan, JSON_UNESCAPED_SLASHES));

            return $plan['success'] ? self::SUCCESS : self::FAILURE;
        }
        $result = $rollback->rollback((string) $this->argument('code'), $this->option('operation') ?: null, (string) $plan['fingerprint'], ArtifactReviewActor::cli());
        $this->line($result['code'].': '.$result['message']);

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
