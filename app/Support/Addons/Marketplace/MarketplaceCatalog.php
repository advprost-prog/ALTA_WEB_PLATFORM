<?php

namespace App\Support\Addons\Marketplace;

use App\Support\Addons\AddonEventLogger;
use App\Support\Addons\AddonRegistry;
use Throwable;

/**
 * Reads the local marketplace catalog (config/addons-marketplace.php),
 * parses each entry into a MarketplaceItem, validates required fields, and
 * collects diagnostics/warnings without ever throwing.
 */
final class MarketplaceCatalog
{
    public function __construct(
        private readonly AddonEventLogger $events,
        private readonly AddonRegistry $registry,
    ) {}

    /**
     * @return array{
     *     items: array<int, MarketplaceItem>,
     *     diagnostics: array<int, string>,
     *     warnings: array<int, string>
     * }
     */
    public function load(): array
    {
        $items = [];
        $diagnostics = [];
        $warnings = [];

        try {
            $raw = config('addons-marketplace.items', []);
        } catch (Throwable $exception) {
            return [
                'items' => [],
                'diagnostics' => ['Marketplace catalog config could not be read: '.$exception->getMessage()],
                'warnings' => [],
            ];
        }

        if (! is_array($raw)) {
            return [
                'items' => [],
                'diagnostics' => ['Marketplace catalog [items] must be an array.'],
                'warnings' => [],
            ];
        }

        $seenCodes = [];

        foreach ($raw as $index => $entry) {
            if (! is_array($entry)) {
                $warnings[] = "Catalog entry #{$index} is not an object and was skipped.";

                continue;
            }

            $item = MarketplaceItem::fromArray($entry);

            if (! $this->visible($item)) {
                continue;
            }

            if (! $item->isValid()) {
                $diagnostics[] = "Invalid marketplace item [{$item->code}]: ".implode(' ', $item->errors);

                if ($item->code !== '' && $this->registry->find($item->code) !== null) {
                    $this->events->warning($item->code, 'marketplace_item_invalid', 'Invalid marketplace catalog item.', [
                        'errors' => $item->errors,
                    ]);
                }
            }

            if ($item->path !== null && ! is_file(base_path($item->path))) {
                $warnings[] = "Marketplace item [{$item->code}] manifest not found at [{$item->path}].";
            }

            if ($item->code !== '' && isset($seenCodes[$item->code])) {
                $diagnostics[] = "Duplicate marketplace code [{$item->code}].";

                if ($this->registry->find($item->code) !== null) {
                    $this->events->warning($item->code, 'marketplace_duplicate_code', 'Duplicate marketplace catalog code.', []);
                }
            }

            if ($item->code !== '') {
                $seenCodes[$item->code] = true;
            }

            $items[] = $item;
        }

        usort($items, static fn (MarketplaceItem $a, MarketplaceItem $b): int => $a->sortOrder <=> $b->sortOrder ?: strcmp($a->name, $b->name));

        return [
            'items' => $items,
            'diagnostics' => $diagnostics,
            'warnings' => $warnings,
        ];
    }

    private function visible(MarketplaceItem $item): bool
    {
        if ($item->visibility === 'production') {
            return true;
        }
        $configured = config('addons-marketplace.show_development');
        $development = $configured === null
            ? app()->environment(['local', 'testing'])
            : filter_var($configured, FILTER_VALIDATE_BOOL);

        return $item->visibility === 'development' ? $development : app()->environment('testing');
    }
}
