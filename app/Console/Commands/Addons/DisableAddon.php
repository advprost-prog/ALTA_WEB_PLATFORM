<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\AddonManager;
use Illuminate\Console\Command;
use RuntimeException;

class DisableAddon extends Command
{
    protected $signature = 'addons:disable {code : Addon code}';

    protected $description = 'Disable an enabled addon.';

    public function handle(AddonManager $addons): int
    {
        try {
            $addon = $addons->disable((string) $this->argument('code'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Addon [{$addon->code}] disabled.");

        return self::SUCCESS;
    }
}
