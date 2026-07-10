<?php

namespace App\Console\Commands\Addons;

use App\Support\Addons\Marketplace\CompatibilityStatus;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Marketplace\UpdateStatus;
use App\Support\Addons\Registry\RegistryCatalog;
use Illuminate\Console\Command;

class MarketplaceCommand extends Command
{
    protected $signature = 'addons:marketplace {--json : Output machine-readable JSON} {--refresh-registry : Force refresh remote registry catalog}';

    protected $description = 'List local marketplace catalog items and their computed lifecycle status.';

    public function handle(MarketplaceManager $manager): int
    {
        if ($this->option('refresh-registry')) {
            try {
                app(RegistryCatalog::class)->flush();
            } catch (\Throwable $exception) {
                $this->warn('Registry refresh failed: '.$exception->getMessage());
            }
        }
        $resolved = $manager->resolve();
        $rows = $resolved['rows'];

        if ($this->option('json')) {
            $payload = [
                'items' => array_map(static fn (array $row): array => [
                    'code' => $row['item']->code,
                    'type' => $row['item']->type,
                    'source' => $row['source'] ?? 'local',
                    'available_version' => $row['available_version'],
                    'installed_version' => $row['installed_version'],
                    'remote_version' => $row['remote_version'] ?? null,
                    'update_status' => $row['update_status'],
                    'compatibility_status' => $row['compatibility_status'],
                    'artifact_status' => $row['artifact_status'] ?? null,
                    'signature_status' => $row['signature_status'] ?? null,
                    'manifest_status' => $row['manifest_status'] ?? null,
                    'trust_status' => $row['trust_status'] ?? null,
                    'review_status' => $row['review_status'] ?? null,
                    'vendor' => $row['item']->vendor,
                    'status' => $row['status'],
                    'valid' => $row['item']->isValid(),
                    'dependencies' => $row['item']->getDependencies(),
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
            ['Code', 'Type', 'Src', 'Avail', 'Installed', 'Remote', 'Update', 'Compat', 'Status', 'Artifact', 'Trust', 'Featured', 'Dependencies', 'DepState', 'Warnings'],
            array_map(static fn (array $row): array => [
                $row['item']->code,
                $row['item']->type,
                $row['source'] ?? 'local',
                $row['available_version'] ?? '-',
                $row['installed_version'] ?? '-',
                $row['remote_version'] ?? '-',
                UpdateStatus::label($row['update_status']),
                CompatibilityStatus::label($row['compatibility_status']),
                $row['status'],
                $row['artifact_status'] ?? '-',
                $row['trust_status'] ?? '-',
                $row['item']->isFeatured ? 'yes' : 'no',
                $row['item']->getDependencyCodes() === [] ? '-' : implode(', ', $row['item']->getDependencyCodes()),
                self::dependencyState($row),
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

    /**
     * @param  array<string, mixed>  $row
     */
    private static function dependencyState(array $row): string
    {
        $codes = $row['item']->getDependencyCodes();

        if ($codes === []) {
            return '-';
        }

        if ($row['dependency_issues'] === []) {
            return 'ok';
        }

        if (isset($row['blocked_reasons']) && $row['blocked_reasons'] !== []) {
            return 'blocked';
        }

        return 'warn';
    }
}
