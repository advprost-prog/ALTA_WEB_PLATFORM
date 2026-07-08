<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Marketplace\MarketplaceManager;
use Illuminate\Console\Command;

class MarketplaceCommand extends Command
{
    protected $signature = 'addons:marketplace {--json : Output machine-readable JSON}';

    protected $description = 'List local marketplace catalog items and their computed lifecycle status.';

    public function handle(MarketplaceManager $manager): int
    {
        $resolved = $manager->resolve();
        $rows = $resolved['rows'];

        if ($this->option('json')) {
            $payload = [
                'items' => array_map(static fn (array $row): array => [
                    'code' => $row['item']->code,
                    'type' => $row['item']->type,
                    'name' => $row['item']->name,
                    'version' => $row['item']->version,
                    'vendor' => $row['item']->vendor,
                    'status' => $row['status'],
                    'valid' => $row['item']->isValid(),
                    'dependencies' => $row['item']->dependencies,
                    'warnings' => $row['warnings'],
                    'actions' => $row['actions'],
                ], $rows),
                'diagnostics' => $resolved['diagnostics'],
                'warnings' => $resolved['warnings'],
            ];

            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $resolved['diagnostics'] === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('Marketplace catalog is empty. See config/addons-marketplace.php.');

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Type', 'Version', 'Status', 'Featured', 'Dependencies', 'Warnings'],
            array_map(static fn (array $row): array => [
                $row['item']->code,
                $row['item']->type,
                $row['item']->version,
                $row['status'],
                $row['item']->isFeatured ? 'yes' : 'no',
                $row['item']->dependencies === [] ? '-' : implode(', ', $row['item']->dependencies),
                $row['warnings'] === [] ? '-' : implode('; ', $row['warnings']),
            ], $rows),
        );

        if ($resolved['diagnostics'] !== []) {
            $this->warn('Diagnostics:');
            foreach ($resolved['diagnostics'] as $diagnostic) {
                $this->line('  - '.$diagnostic);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
