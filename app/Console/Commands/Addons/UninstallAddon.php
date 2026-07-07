<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\AddonManager;
use Illuminate\Console\Command;
use RuntimeException;

class UninstallAddon extends Command
{
    protected $signature = 'addons:uninstall {code : Addon code}';

    protected $description = 'Soft-uninstall an addon without deleting files.';

    public function handle(AddonManager $addons): int
    {
        try {
            $addon = $addons->uninstall((string) $this->argument('code'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Addon [{$addon->code}] uninstalled. Files were not removed.");

        return self::SUCCESS;
    }
}
