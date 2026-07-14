<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\AddonRecoveryService;
use App\Support\Addons\Registry\ArtifactReviewActor;
use Illuminate\Console\Command;

final class RecoveryRun extends Command
{
    protected $signature = 'addons:recovery:run {operation-id} {--safe-only} {--dry-run}';

    protected $description = 'Run an explicitly approved safe addon recovery plan.';

    public function handle(AddonRecoveryService $recovery): int
    {
        $assessment = $recovery->inspect((string) $this->argument('operation-id'));
        if ($assessment === null) {
            $this->error('journal_invalid');

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $plan = $recovery->plan($assessment->operationId);
            $this->line(json_encode($plan, JSON_UNESCAPED_SLASHES));

            return $plan['success'] ? self::SUCCESS : self::FAILURE;
        }
        $result = $recovery->recover($assessment->operationId, $assessment->fingerprint, ArtifactReviewActor::cli());
        $this->line($result['code'].': '.$result['message']);

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
