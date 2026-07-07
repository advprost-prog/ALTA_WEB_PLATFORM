<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Commerce\CustomerService;
use Illuminate\Console\Command;

class BackfillCustomersFromOrders extends Command
{
    protected $signature = 'customers:backfill-from-orders
        {--dry-run : Show what would be changed without mutating data}
        {--limit= : Maximum number of orders to scan}';

    protected $description = 'Create/link customer master data from existing order snapshots without changing order history.';

    public function handle(CustomerService $customers): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->normalizedLimit();

        if ($limit === false) {
            $this->error('Invalid --limit value.');

            return self::FAILURE;
        }

        $query = Order::query()
            ->whereNull('customer_id')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $orders = $query->get();
        $summary = [
            'scanned' => 0,
            'created_customers' => 0,
            'linked_orders' => 0,
            'skipped' => 0,
            'potential_duplicates' => 0,
        ];

        foreach ($orders as $order) {
            $summary['scanned']++;

            $data = $this->dataFromOrder($order);

            if (! $this->hasCustomerData($data)) {
                $summary['skipped']++;

                continue;
            }

            [$phoneMatch, $emailMatch] = $this->matchesFor($customers, $data);
            $hasConflict = $phoneMatch && $emailMatch && $phoneMatch->id !== $emailMatch->id;

            if ($hasConflict) {
                $summary['potential_duplicates']++;
            }

            if ($dryRun) {
                if (! $phoneMatch && ! $emailMatch) {
                    $summary['created_customers']++;
                }

                $summary['linked_orders']++;

                continue;
            }

            $beforeCount = Customer::query()->count();
            $customer = $customers->resolveFromCheckout($data);
            $afterCount = Customer::query()->count();

            if ($afterCount > $beforeCount) {
                $summary['created_customers']++;
            }

            if ($customers->findPotentialDuplicates($customer)->isNotEmpty()) {
                $summary['potential_duplicates']++;
            }

            $order->forceFill(['customer_id' => $customer->id])->save();
            $summary['linked_orders']++;
        }

        $this->line('dry_run: '.($dryRun ? 'yes' : 'no'));
        $this->line('limit: '.($limit ?? 'all'));
        $this->line('scanned: '.$summary['scanned']);
        $this->line('created_customers: '.$summary['created_customers']);
        $this->line('linked_orders: '.$summary['linked_orders']);
        $this->line('skipped: '.$summary['skipped']);
        $this->line('potential_duplicates: '.$summary['potential_duplicates']);

        return self::SUCCESS;
    }

    private function normalizedLimit(): int|false|null
    {
        $value = $this->option('limit');

        if ($value === null || $value === '') {
            return null;
        }

        if (! ctype_digit((string) $value) || (int) $value < 1) {
            return false;
        }

        return min((int) $value, 10000);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataFromOrder(Order $order): array
    {
        return [
            'customer_name' => $order->customer_name,
            'name' => $order->customer_name,
            'phone' => $order->phone,
            'email' => $order->email,
            'city' => $order->city,
            'address' => $order->address,
            'delivery_method_id' => $order->delivery_method_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasCustomerData(array $data): bool
    {
        foreach (['customer_name', 'phone', 'email', 'city', 'address'] as $key) {
            if (filled($data[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Customer|null, 1: Customer|null}
     */
    private function matchesFor(CustomerService $customers, array $data): array
    {
        $normalizedPhone = $customers->normalizePhone(is_scalar($data['phone'] ?? null) ? (string) $data['phone'] : null);
        $normalizedEmail = $customers->normalizeEmail(is_scalar($data['email'] ?? null) ? (string) $data['email'] : null);

        return [
            $normalizedPhone ? Customer::query()->where('normalized_phone', $normalizedPhone)->oldest('id')->first() : null,
            $normalizedEmail ? Customer::query()->where('normalized_email', $normalizedEmail)->oldest('id')->first() : null,
        ];
    }
}
