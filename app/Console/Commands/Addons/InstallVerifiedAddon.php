<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Registry\ArtifactReviewActor;
use App\Support\Addons\Registry\VerifiedAddonInstallOrchestrator;
use Illuminate\Console\Command;

final class InstallVerifiedAddon extends Command
{
    protected $signature = 'addons:install-verified {code : Approved and staged addon code} {--enable : Enable after a first install}';

    protected $description = 'Atomically install or update an approved verified Marketplace artifact.';

    public function handle(VerifiedAddonInstallOrchestrator $orchestrator): int
    {
        $result = $orchestrator->execute(trim((string) $this->argument('code')), ArtifactReviewActor::cli(), (bool) $this->option('enable'));
        if (! $result->success) {
            $this->error(($result->failureCode ?? 'operation_failed').': '.implode(' ', $result->diagnostics));

            return self::FAILURE;
        }

        $this->info("{$result->code} {$result->version} installed; state=".($result->enabled ? 'enabled' : 'disabled')."; operation={$result->operationId}");

        return self::SUCCESS;
    }
}
