<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('symbol')->nullable();
            $table->unsignedTinyInteger('precision')->default(2);
            $table->decimal('rate_to_base', 18, 6)->nullable();
            $table->boolean('is_base')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->text('address')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('commerce_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('multi_currency_enabled')->default(false);
            $table->boolean('multi_warehouse_enabled')->default(false);
            $table->foreignId('default_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('price', 12, 2);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'currency_id']);
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('type');
            $table->decimal('quantity', 12, 3);
            $table->decimal('balance_after', 12, 3)->nullable();
            $table->nullableMorphs('related');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('customer_id')->constrained('currencies')->restrictOnDelete();
            $table->string('currency_code')->nullable()->after('currency_id');
            $table->decimal('exchange_rate_to_base', 18, 6)->nullable()->after('currency_code');
            $table->foreignId('warehouse_id')->nullable()->after('exchange_rate_to_base')->constrained('warehouses')->restrictOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('product_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('unit_price', 12, 2)->nullable()->after('quantity');
        });

        DB::table('order_items')->update([
            'unit_price' => DB::raw('price'),
        ]);

        $now = now();

        $currencyId = DB::table('currencies')->insertGetId([
            'code' => 'UAH',
            'name' => 'Українська гривня',
            'symbol' => '₴',
            'precision' => 2,
            'rate_to_base' => '1.000000',
            'is_base' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $warehouseId = DB::table('warehouses')->insertGetId([
            'name' => 'Основний склад',
            'code' => 'main',
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('commerce_settings')->insert([
            'multi_currency_enabled' => false,
            'multi_warehouse_enabled' => false,
            'default_currency_id' => $currencyId,
            'default_warehouse_id' => $warehouseId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('products')
            ->select(['id', 'price', 'old_price', 'stock'])
            ->orderBy('id')
            ->get()
            ->each(function (object $product) use ($currencyId, $warehouseId, $now): void {
                DB::table('product_prices')->insert([
                    'product_id' => $product->id,
                    'currency_id' => $currencyId,
                    'price' => $product->price,
                    'compare_at_price' => $product->old_price,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('stock_balances')->insert([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $product->stock,
                    'reserved_quantity' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        DB::table('orders')->update([
            'currency_id' => $currencyId,
            'currency_code' => 'UAH',
            'exchange_rate_to_base' => '1.000000',
            'warehouse_id' => $warehouseId,
        ]);

        DB::table('order_items')->whereNull('warehouse_id')->update([
            'warehouse_id' => $warehouseId,
        ]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropColumn('unit_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn(['currency_code', 'exchange_rate_to_base']);
        });

        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('commerce_settings');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('currencies');
    }
};
