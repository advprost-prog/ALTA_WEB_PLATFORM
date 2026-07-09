<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Marketplace\MarketplaceManager;
use Illuminate\Console\Command;

class DownloadAddon extends Command
{
    protected $signature = 'addons:download {code : Addon code to download into quarantine}';

    protected $description = 'Download a remote addon artifact into quarantine without installing it.';

    public function handle(MarketplaceManager $manager): int
    {
        $code = (string) $this->argument('code');

        $result = $manager->downloadArtifact($code);

        if (! $result->success) {
            $this->error("Artifact для [{$code}] не завантажено.");

            foreach ($result->diagnostics as $diagnostic) {
                $this->line('  - '.$diagnostic);
            }

            return self::FAILURE;
        }

        $this->info("Artifact для [{$code}] завантажено та поміщено у quarantine.");

        $this->line('  status: '.$result->status);
        $this->line('  path:   '.$result->path);

        if ($result->metadata !== null) {
            $this->line('  sha256: '.$result->metadata['sha256']);
            $this->line('  size:   '.$result->metadata['size']);
            $this->line('  meta:   '.$result->metadataPath);
        }

        $this->warn('Addon НЕ встановлено і не розпаковано. Це лише quarantine-завантаження.');

        return self::SUCCESS;
    }
}
