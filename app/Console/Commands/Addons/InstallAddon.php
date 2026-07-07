<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\AddonManager;
use Illuminate\Console\Command;
use RuntimeException;

class InstallAddon extends Command
{
    protected $signature = 'addons:install {code : Addon code}';

    protected $description = 'Install a locally discovered addon.';

    public function handle(AddonManager $addons): int
    {
        try {
            $addon = $addons->install((string) $this->argument('code'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Addon [{$addon->code}] installed.");

        return self::SUCCESS;
    }
}
