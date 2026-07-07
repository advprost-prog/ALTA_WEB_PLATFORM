<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\AddonManager;
use Illuminate\Console\Command;
use RuntimeException;

class EnableAddon extends Command
{
    protected $signature = 'addons:enable {code : Addon code}';

    protected $description = 'Enable an installed local addon.';

    public function handle(AddonManager $addons): int
    {
        try {
            $addon = $addons->enable((string) $this->argument('code'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Addon [{$addon->code}] enabled.");

        return self::SUCCESS;
    }
}
