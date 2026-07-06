<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('delivery_methods')) {
            Schema::create('delivery_methods', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        $this->seedMethods();

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'payment_status')) {
                $table->string('payment_status')->default('unpaid')->after('status')->index();
            }

            if (! Schema::hasColumn('orders', 'delivery_status')) {
                $table->string('delivery_status')->default('pending')->after('payment_status')->index();
            }

            if (! Schema::hasColumn('orders', 'delivery_method_id')) {
                $table->foreignId('delivery_method_id')->nullable()->after('delivery_method')->constrained('delivery_methods')->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'delivery_method_name')) {
                $table->string('delivery_method_name')->nullable()->after('delivery_method_id');
            }

            if (! Schema::hasColumn('orders', 'payment_method_id')) {
                $table->foreignId('payment_method_id')->nullable()->after('payment_method')->constrained('payment_methods')->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'payment_method_name')) {
                $table->string('payment_method_name')->nullable()->after('payment_method_id');
            }

            if (! Schema::hasColumn('orders', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('manager_comment');
            }

            if (! Schema::hasColumn('orders', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('confirmed_at');
            }

            if (! Schema::hasColumn('orders', 'shipped_at')) {
                $table->timestamp('shipped_at')->nullable()->after('paid_at');
            }

            if (! Schema::hasColumn('orders', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('shipped_at');
            }

            if (! Schema::hasColumn('orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            }

            if (! Schema::hasColumn('orders', 'cancel_reason')) {
                $table->text('cancel_reason')->nullable()->after('cancelled_at');
            }
        });

        if (! Schema::hasTable('order_status_histories')) {
            Schema::create('order_status_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->string('type')->index();
                $table->string('from_value')->nullable();
                $table->string('to_value')->nullable();
                $table->text('comment')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['order_id', 'created_at']);
            });
        }

        $this->backfillOrders();
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'delivery_method_id')) {
                $table->dropConstrainedForeignId('delivery_method_id');
            }

            if (Schema::hasColumn('orders', 'payment_method_id')) {
                $table->dropConstrainedForeignId('payment_method_id');
            }

            $columns = array_values(array_filter([
                'payment_status',
                'delivery_status',
                'delivery_method_name',
                'payment_method_name',
                'confirmed_at',
                'paid_at',
                'shipped_at',
                'completed_at',
                'cancelled_at',
                'cancel_reason',
            ], fn (string $column): bool => Schema::hasColumn('orders', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::dropIfExists('delivery_methods');
        Schema::dropIfExists('payment_methods');
    }

    private function seedMethods(): void
    {
        $now = now();

        foreach ([
            ['code' => 'cash_on_delivery', 'name' => 'Післяплата', 'description' => 'Оплата при отриманні замовлення.', 'sort_order' => 10],
            ['code' => 'bank_transfer', 'name' => 'Банківський переказ', 'description' => 'Оплата за рахунком після підтвердження менеджером.', 'sort_order' => 20],
            ['code' => 'cash', 'name' => 'Готівка', 'description' => 'Оплата готівкою в магазині.', 'sort_order' => 30],
        ] as $method) {
            DB::table('payment_methods')->updateOrInsert(
                ['code' => $method['code']],
                $method + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        foreach ([
            ['code' => 'nova_poshta', 'name' => 'Нова пошта', 'description' => 'Відправка через відділення або поштомат Нової пошти.', 'sort_order' => 10],
            ['code' => 'pickup', 'name' => 'Самовивіз', 'description' => 'Отримання замовлення в магазині після підтвердження.', 'sort_order' => 20],
            ['code' => 'courier', 'name' => 'Кур’єрська доставка', 'description' => 'Адресна доставка кур’єром після узгодження з менеджером.', 'sort_order' => 30],
        ] as $method) {
            DB::table('delivery_methods')->updateOrInsert(
                ['code' => $method['code']],
                $method + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    private function backfillOrders(): void
    {
        DB::table('orders')->whereNull('status')->update(['status' => 'new']);
        DB::table('orders')->whereNull('payment_status')->update(['payment_status' => 'unpaid']);
        DB::table('orders')->whereNull('delivery_status')->update(['delivery_status' => 'pending']);
        DB::table('orders')->whereNull('payment_method_name')->whereNotNull('payment_method')->update(['payment_method_name' => DB::raw('payment_method')]);
        DB::table('orders')->whereNull('delivery_method_name')->whereNotNull('delivery_method')->update(['delivery_method_name' => DB::raw('delivery_method')]);

        $paymentAliases = [
            'cash_on_delivery' => ['cash_on_delivery', 'Післяплата'],
            'bank_transfer' => ['bank_transfer', 'Банківський переказ', 'Безготівковий рахунок'],
            'cash' => ['cash', 'Готівка'],
        ];

        foreach ($paymentAliases as $code => $aliases) {
            $method = DB::table('payment_methods')->where('code', $code)->first();

            if (! $method) {
                continue;
            }

            DB::table('orders')
                ->whereNull('payment_method_id')
                ->where(function ($query) use ($aliases) {
                    $query->whereIn('payment_method', $aliases)
                        ->orWhereIn('payment_method_name', $aliases);
                })
                ->update([
                    'payment_method_id' => $method->id,
                    'payment_method_name' => $method->name,
                ]);
        }

        $deliveryAliases = [
            'nova_poshta' => ['nova_poshta', 'Нова пошта'],
            'pickup' => ['pickup', 'Самовивіз'],
            'courier' => ['courier', 'Кур’єрська доставка', 'Курʼєрська доставка', 'Кур’єр', 'Курʼєр', 'Кур\'єр'],
        ];

        foreach ($deliveryAliases as $code => $aliases) {
            $method = DB::table('delivery_methods')->where('code', $code)->first();

            if (! $method) {
                continue;
            }

            DB::table('orders')
                ->whereNull('delivery_method_id')
                ->where(function ($query) use ($aliases) {
                    $query->whereIn('delivery_method', $aliases)
                        ->orWhereIn('delivery_method_name', $aliases);
                })
                ->update([
                    'delivery_method_id' => $method->id,
                    'delivery_method_name' => $method->name,
                ]);
        }
    }
};
