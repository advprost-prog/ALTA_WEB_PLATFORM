<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $customerColumns = Schema::getColumnListing('customers');

        Schema::table('customers', function (Blueprint $table) use ($customerColumns): void {
            if (! in_array('type', $customerColumns, true)) {
                $table->string('type')->default('individual')->after('id')->index();
            }

            if (! in_array('first_name', $customerColumns, true)) {
                $table->string('first_name')->nullable()->after('type');
            }

            if (! in_array('last_name', $customerColumns, true)) {
                $table->string('last_name')->nullable()->after('first_name');
            }

            if (! in_array('middle_name', $customerColumns, true)) {
                $table->string('middle_name')->nullable()->after('last_name');
            }

            if (! in_array('full_name', $customerColumns, true)) {
                $table->string('full_name')->nullable()->after('middle_name');
            }

            if (! in_array('company_name', $customerColumns, true)) {
                $table->string('company_name')->nullable()->after('full_name');
            }

            if (! in_array('normalized_email', $customerColumns, true)) {
                $table->string('normalized_email')->nullable()->after('email')->index();
            }

            if (! in_array('normalized_phone', $customerColumns, true)) {
                $table->string('normalized_phone')->nullable()->after('phone')->index();
            }

            if (! in_array('tax_id', $customerColumns, true)) {
                $table->string('tax_id')->nullable()->after('normalized_phone');
            }

            if (! in_array('edrpou', $customerColumns, true)) {
                $table->string('edrpou')->nullable()->after('tax_id');
            }

            if (! in_array('is_active', $customerColumns, true)) {
                $table->boolean('is_active')->default(true)->after('notes')->index();
            }

            if (! in_array('marketing_consent', $customerColumns, true)) {
                $table->boolean('marketing_consent')->default(false)->after('is_active');
            }
        });

        $this->backfillCustomers();

        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'name')) {
                $table->string('name')->nullable()->change();
            }

            if (Schema::hasColumn('customers', 'phone')) {
                $table->string('phone')->nullable()->change();
            }
        });

        if (! Schema::hasTable('customer_addresses')) {
            Schema::create('customer_addresses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->string('type')->default('delivery')->index();
                $table->string('recipient_name')->nullable();
                $table->string('recipient_phone')->nullable();
                $table->string('city')->nullable();
                $table->text('address')->nullable();
                $table->string('postal_code')->nullable();
                $table->foreignId('delivery_method_id')->nullable()->constrained('delivery_methods')->nullOnDelete();
                $table->string('provider')->nullable();
                $table->string('warehouse_ref')->nullable();
                $table->boolean('is_default')->default(false)->index();
                $table->timestamps();

                $table->index(['customer_id', 'type', 'is_default']);
            });
        }

        $orderColumns = Schema::getColumnListing('orders');

        Schema::table('orders', function (Blueprint $table) use ($orderColumns): void {
            if (! in_array('city', $orderColumns, true)) {
                $table->string('city')->nullable()->after('email');
            }

            if (! in_array('address', $orderColumns, true)) {
                $table->text('address')->nullable()->after('city');
            }
        });

        $this->backfillOrderAddressSnapshots();
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');

        Schema::table('orders', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'address',
                'city',
            ], fn (string $column): bool => Schema::hasColumn('orders', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'type',
                'first_name',
                'last_name',
                'middle_name',
                'full_name',
                'company_name',
                'normalized_email',
                'normalized_phone',
                'tax_id',
                'edrpou',
                'is_active',
                'marketing_consent',
            ], fn (string $column): bool => Schema::hasColumn('customers', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function backfillCustomers(): void
    {
        DB::table('customers')
            ->orderBy('id')
            ->chunkById(200, function ($customers): void {
                foreach ($customers as $customer) {
                    $name = $this->clean($customer->full_name ?? null)
                        ?: $this->clean($customer->name ?? null);

                    DB::table('customers')
                        ->where('id', $customer->id)
                        ->update([
                            'type' => $customer->type ?? 'individual',
                            'full_name' => $name,
                            'normalized_email' => $this->normalizeEmail($customer->email ?? null),
                            'normalized_phone' => $this->normalizePhone($customer->phone ?? null),
                            'is_active' => (bool) ($customer->is_active ?? true),
                            'marketing_consent' => (bool) ($customer->marketing_consent ?? false),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    private function backfillOrderAddressSnapshots(): void
    {
        DB::table('orders')
            ->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
            ->whereNotNull('orders.customer_id')
            ->where(function ($query): void {
                $query->whereNull('orders.city')
                    ->orWhereNull('orders.address');
            })
            ->orderBy('orders.id')
            ->select([
                'orders.id',
                'orders.city as order_city',
                'orders.address as order_address',
                'customers.city as customer_city',
                'customers.address as customer_address',
            ])
            ->chunkById(200, function ($orders): void {
                foreach ($orders as $order) {
                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update([
                            'city' => $order->order_city ?: $order->customer_city,
                            'address' => $order->order_address ?: $order->customer_address,
                            'updated_at' => now(),
                        ]);
                }
            }, 'orders.id', 'id');
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = $this->clean($email);

        return $email === null ? null : mb_strtolower($email);
    }

    private function normalizePhone(?string $phone): ?string
    {
        $phone = $this->clean($phone);

        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '38'.$digits;
        }

        return $digits === '' ? null : $digits;
    }
};
