<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createUnitsTable();
        $this->createTaxProfilesTable();
        $this->extendCategoriesTable();
        $this->extendBrandsTable();
        $this->extendProductsTable();
        $this->createProductVariantsTable();
        $this->createVariantPackagesTable();
        $this->createProductBarcodesTable();
        $this->createAttributesTable();
        $this->createProductAttributeValuesTable();
        $this->createCategoryAttributesTable();
        $this->extendProductImagesTable();
        $this->createProductCategoryPivot();
        $this->extendProductPricesTable();
        $this->extendStockBalancesTable();
        $this->extendStockMovementsTable();
        $this->extendOrderItemsTable();

        $this->seedUnits();
        $this->seedTaxProfiles();
        $this->backfillDefaultVariantsAndLinks();
    }

    public function down(): void
    {
        $this->dropOrderItemColumns();
        $this->dropStockMovementColumns();
        $this->dropStockBalanceColumns();
        $this->dropProductPriceColumns();
        Schema::dropIfExists('product_category');
        $this->dropProductImageColumns();
        Schema::dropIfExists('category_attributes');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('variant_packages');
        Schema::dropIfExists('product_variants');
        $this->dropProductColumns();
        $this->dropBrandColumns();
        $this->dropCategoryColumns();
        Schema::dropIfExists('tax_profiles');
        Schema::dropIfExists('units');
    }

    private function createUnitsTable(): void
    {
        if (Schema::hasTable('units')) {
            return;
        }

        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('short_name');
            $table->string('code')->unique();
            $table->string('international_code')->nullable();
            $table->string('type')->nullable();
            $table->unsignedTinyInteger('precision')->default(0);
            $table->boolean('is_fractional')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    private function createTaxProfilesTable(): void
    {
        if (Schema::hasTable('tax_profiles')) {
            return;
        }

        Schema::create('tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->boolean('price_includes_tax')->default(true);
            $table->string('fiscal_group_code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    private function extendCategoriesTable(): void
    {
        $columns = Schema::getColumnListing('categories');

        Schema::table('categories', function (Blueprint $table) use ($columns): void {
            if (! in_array('banner_image', $columns, true)) {
                $table->string('banner_image')->nullable()->after('image');
            }

            if (! in_array('icon', $columns, true)) {
                $table->string('icon')->nullable()->after('banner_image');
            }

            if (! in_array('is_visible_in_menu', $columns, true)) {
                $table->boolean('is_visible_in_menu')->default(true)->after('is_active')->index();
            }

            if (! in_array('default_tax_profile_id', $columns, true)) {
                $table->foreignId('default_tax_profile_id')->nullable()->after('is_visible_in_menu')->constrained('tax_profiles')->nullOnDelete();
            }
        });
    }

    private function extendBrandsTable(): void
    {
        $columns = Schema::getColumnListing('brands');

        Schema::table('brands', function (Blueprint $table) use ($columns): void {
            if (! in_array('seo_title', $columns, true)) {
                $table->string('seo_title')->nullable()->after('description');
            }

            if (! in_array('seo_description', $columns, true)) {
                $table->text('seo_description')->nullable()->after('seo_title');
            }

            if (! in_array('sort_order', $columns, true)) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_active');
            }
        });
    }

    private function extendProductsTable(): void
    {
        $columns = Schema::getColumnListing('products');

        Schema::table('products', function (Blueprint $table) use ($columns): void {
            if (! in_array('status', $columns, true)) {
                $table->string('status')->default('draft')->after('main_image')->index();
            }

            if (! in_array('is_featured', $columns, true)) {
                $table->boolean('is_featured')->default(false)->after('status')->index();
            }

            if (! in_array('sort_order', $columns, true)) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_featured');
            }
        });

        DB::table('products')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update([
                'status' => DB::raw("CASE WHEN is_active = 1 THEN 'active' ELSE 'draft' END"),
            ]);
    }

    private function createProductVariantsTable(): void
    {
        if (Schema::hasTable('product_variants')) {
            return;
        }

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('barcode')->nullable();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('sales_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('purchase_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('tax_profile_id')->constrained('tax_profiles')->restrictOnDelete();
            $table->boolean('is_excise_applicable')->default(false)->index();
            $table->decimal('excise_rate', 5, 2)->nullable();
            $table->boolean('requires_excise_stamp_entry')->default(false)->index();
            $table->decimal('weight', 10, 3)->nullable();
            $table->decimal('length', 10, 3)->nullable();
            $table->decimal('width', 10, 3)->nullable();
            $table->decimal('height', 10, 3)->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('external_source')->nullable();
            $table->string('external_id')->nullable();
            $table->string('external_code')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'is_default']);
        });
    }

    private function createVariantPackagesTable(): void
    {
        if (Schema::hasTable('variant_packages')) {
            return;
        }

        Schema::create('variant_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('name');
            $table->decimal('quantity_in_base_unit', 12, 3)->default(1);
            $table->string('barcode')->nullable();
            $table->boolean('is_default_sales_package')->default(false)->index();
            $table->decimal('weight', 10, 3)->nullable();
            $table->decimal('length', 10, 3)->nullable();
            $table->decimal('width', 10, 3)->nullable();
            $table->decimal('height', 10, 3)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    private function createProductBarcodesTable(): void
    {
        if (Schema::hasTable('product_barcodes')) {
            return;
        }

        Schema::create('product_barcodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('variant_package_id')->nullable()->constrained('variant_packages')->nullOnDelete();
            $table->string('barcode');
            $table->string('type')->default('ean13');
            $table->boolean('is_primary')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['barcode', 'is_active']);
        });
    }

    private function createAttributesTable(): void
    {
        if (Schema::hasTable('attributes')) {
            return;
        }

        Schema::create('attributes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('type')->default('text');
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->boolean('is_filterable')->default(false)->index();
            $table->boolean('is_comparable')->default(false)->index();
            $table->boolean('is_visible_on_product')->default(true)->index();
            $table->boolean('is_required')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    private function createProductAttributeValuesTable(): void
    {
        if (Schema::hasTable('product_attribute_values')) {
            return;
        }

        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->decimal('value_number', 14, 3)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'attribute_id']);
        });
    }

    private function createCategoryAttributesTable(): void
    {
        if (Schema::hasTable('category_attributes')) {
            return;
        }

        Schema::create('category_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->boolean('is_required')->default(false)->index();
            $table->boolean('is_filterable')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'attribute_id']);
        });
    }

    private function extendProductImagesTable(): void
    {
        $columns = Schema::getColumnListing('product_images');

        Schema::table('product_images', function (Blueprint $table) use ($columns): void {
            if (! in_array('product_variant_id', $columns, true)) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            }

            if (! in_array('type', $columns, true)) {
                $table->string('type')->default('image')->after('product_variant_id');
            }
        });
    }

    private function createProductCategoryPivot(): void
    {
        if (Schema::hasTable('product_category')) {
            return;
        }

        Schema::create('product_category', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->primary(['product_id', 'category_id']);
        });
    }

    private function extendProductPricesTable(): void
    {
        $columns = Schema::getColumnListing('product_prices');

        Schema::table('product_prices', function (Blueprint $table) use ($columns): void {
            if (! in_array('product_variant_id', $columns, true)) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            }
        });
    }

    private function extendStockBalancesTable(): void
    {
        $columns = Schema::getColumnListing('stock_balances');

        Schema::table('stock_balances', function (Blueprint $table) use ($columns): void {
            if (! in_array('product_variant_id', $columns, true)) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            }
        });
    }

    private function extendStockMovementsTable(): void
    {
        $columns = Schema::getColumnListing('stock_movements');

        Schema::table('stock_movements', function (Blueprint $table) use ($columns): void {
            if (! in_array('product_variant_id', $columns, true)) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            }
        });
    }

    private function extendOrderItemsTable(): void
    {
        $columns = Schema::getColumnListing('order_items');

        Schema::table('order_items', function (Blueprint $table) use ($columns): void {
            if (! in_array('product_variant_id', $columns, true)) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            }

            if (! in_array('unit_name', $columns, true)) {
                $table->string('unit_name')->nullable()->after('sku');
            }

            if (! in_array('unit_short_name', $columns, true)) {
                $table->string('unit_short_name')->nullable()->after('unit_name');
            }

            if (! in_array('base_unit_id', $columns, true)) {
                $table->foreignId('base_unit_id')->nullable()->after('unit_short_name')->constrained('units')->nullOnDelete();
            }

            if (! in_array('sales_unit_id', $columns, true)) {
                $table->foreignId('sales_unit_id')->nullable()->after('base_unit_id')->constrained('units')->nullOnDelete();
            }

            if (! in_array('quantity_in_base_unit', $columns, true)) {
                $table->decimal('quantity_in_base_unit', 12, 3)->nullable()->after('quantity');
            }

            if (! in_array('tax_profile_id', $columns, true)) {
                $table->foreignId('tax_profile_id')->nullable()->after('quantity_in_base_unit')->constrained('tax_profiles')->nullOnDelete();
            }

            if (! in_array('tax_profile_name', $columns, true)) {
                $table->string('tax_profile_name')->nullable()->after('tax_profile_id');
            }

            if (! in_array('tax_profile_code', $columns, true)) {
                $table->string('tax_profile_code')->nullable()->after('tax_profile_name');
            }

            if (! in_array('vat_rate', $columns, true)) {
                $table->decimal('vat_rate', 5, 2)->nullable()->after('tax_profile_code');
            }

            if (! in_array('vat_amount', $columns, true)) {
                $table->decimal('vat_amount', 12, 2)->nullable()->after('vat_rate');
            }

            if (! in_array('is_excise_applicable', $columns, true)) {
                $table->boolean('is_excise_applicable')->default(false)->after('vat_amount');
            }

            if (! in_array('excise_rate', $columns, true)) {
                $table->decimal('excise_rate', 5, 2)->nullable()->after('is_excise_applicable');
            }

            if (! in_array('excise_amount', $columns, true)) {
                $table->decimal('excise_amount', 12, 2)->nullable()->after('excise_rate');
            }

            if (! in_array('requires_excise_stamp_entry', $columns, true)) {
                $table->boolean('requires_excise_stamp_entry')->default(false)->after('excise_amount');
            }

            if (! in_array('price_excluding_tax', $columns, true)) {
                $table->decimal('price_excluding_tax', 12, 2)->nullable()->after('unit_price');
            }

            if (! in_array('price_including_tax', $columns, true)) {
                $table->decimal('price_including_tax', 12, 2)->nullable()->after('price_excluding_tax');
            }

            if (! in_array('line_total_excluding_tax', $columns, true)) {
                $table->decimal('line_total_excluding_tax', 12, 2)->nullable()->after('total');
            }

            if (! in_array('line_total_tax_amount', $columns, true)) {
                $table->decimal('line_total_tax_amount', 12, 2)->nullable()->after('line_total_excluding_tax');
            }

            if (! in_array('line_total_including_tax', $columns, true)) {
                $table->decimal('line_total_including_tax', 12, 2)->nullable()->after('line_total_tax_amount');
            }
        });
    }

    private function seedUnits(): void
    {
        $now = now();

        foreach ([
            ['name' => 'Штука', 'short_name' => 'шт', 'code' => 'piece', 'international_code' => 'piece', 'type' => 'count', 'precision' => 0, 'is_fractional' => false, 'sort_order' => 10],
            ['name' => 'Упаковка', 'short_name' => 'уп', 'code' => 'package', 'international_code' => 'package', 'type' => 'count', 'precision' => 0, 'is_fractional' => false, 'sort_order' => 20],
            ['name' => 'Комплект', 'short_name' => 'компл', 'code' => 'set', 'international_code' => 'set', 'type' => 'count', 'precision' => 0, 'is_fractional' => false, 'sort_order' => 30],
            ['name' => 'Кілограм', 'short_name' => 'кг', 'code' => 'kg', 'international_code' => 'kg', 'type' => 'weight', 'precision' => 3, 'is_fractional' => true, 'sort_order' => 40],
            ['name' => 'Грам', 'short_name' => 'г', 'code' => 'g', 'international_code' => 'g', 'type' => 'weight', 'precision' => 3, 'is_fractional' => true, 'sort_order' => 50],
            ['name' => 'Метр', 'short_name' => 'м', 'code' => 'm', 'international_code' => 'm', 'type' => 'length', 'precision' => 3, 'is_fractional' => true, 'sort_order' => 60],
            ['name' => 'Літр', 'short_name' => 'л', 'code' => 'l', 'international_code' => 'l', 'type' => 'volume', 'precision' => 3, 'is_fractional' => true, 'sort_order' => 70],
            ['name' => 'Метр квадратний', 'short_name' => 'м²', 'code' => 'm2', 'international_code' => 'm2', 'type' => 'area', 'precision' => 3, 'is_fractional' => true, 'sort_order' => 80],
            ['name' => 'Метр кубічний', 'short_name' => 'м³', 'code' => 'm3', 'international_code' => 'm3', 'type' => 'volume', 'precision' => 3, 'is_fractional' => true, 'sort_order' => 90],
        ] as $unit) {
            DB::table('units')->updateOrInsert(
                ['code' => $unit['code']],
                $unit + ['is_active' => true, 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    private function seedTaxProfiles(): void
    {
        $now = now();

        foreach ([
            ['name' => 'Без ПДВ', 'code' => 'no_vat', 'vat_rate' => 0, 'price_includes_tax' => true, 'description' => 'Базовий профіль без ПДВ', 'is_default' => true, 'sort_order' => 10],
            ['name' => 'ПДВ 20%', 'code' => 'vat_20', 'vat_rate' => 20, 'price_includes_tax' => true, 'description' => 'Стандартна ставка ПДВ 20%', 'is_default' => false, 'sort_order' => 20],
            ['name' => 'ПДВ 7%', 'code' => 'vat_7', 'vat_rate' => 7, 'price_includes_tax' => true, 'description' => 'Пільгова ставка ПДВ 7%', 'is_default' => false, 'sort_order' => 30],
            ['name' => 'Нульова ставка', 'code' => 'vat_0', 'vat_rate' => 0, 'price_includes_tax' => true, 'description' => 'Нульова ставка ПДВ', 'is_default' => false, 'sort_order' => 40],
        ] as $profile) {
            DB::table('tax_profiles')->updateOrInsert(
                ['code' => $profile['code']],
                $profile + ['is_active' => true, 'updated_at' => $now, 'created_at' => $now],
            );
        }

        DB::table('tax_profiles')
            ->where('code', '!=', 'no_vat')
            ->update(['is_default' => false, 'updated_at' => $now]);
    }

    private function backfillDefaultVariantsAndLinks(): void
    {
        $pieceUnitId = DB::table('units')->where('code', 'piece')->value('id');
        $defaultTaxProfileId = DB::table('tax_profiles')->where('code', 'no_vat')->value('id');
        $now = now();

        if (! $pieceUnitId || ! $defaultTaxProfileId) {
            return;
        }

        DB::table('products')
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($pieceUnitId, $defaultTaxProfileId, $now): void {
                foreach ($products as $product) {
                    $existingVariantId = DB::table('product_variants')
                        ->where('product_id', $product->id)
                        ->where('is_default', true)
                        ->value('id');

                    if (! $existingVariantId) {
                        $existingVariantId = DB::table('product_variants')->insertGetId([
                            'product_id' => $product->id,
                            'sku' => $product->sku,
                            'name' => null,
                            'barcode' => null,
                            'base_unit_id' => $pieceUnitId,
                            'sales_unit_id' => $pieceUnitId,
                            'purchase_unit_id' => $pieceUnitId,
                            'tax_profile_id' => $defaultTaxProfileId,
                            'is_excise_applicable' => false,
                            'excise_rate' => null,
                            'requires_excise_stamp_entry' => false,
                            'is_default' => true,
                            'is_active' => (bool) $product->is_active,
                            'sort_order' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    DB::table('product_category')->updateOrInsert(
                        ['product_id' => $product->id, 'category_id' => $product->category_id],
                        ['is_primary' => true, 'sort_order' => 0],
                    );

                    DB::table('product_prices')
                        ->where('product_id', $product->id)
                        ->whereNull('product_variant_id')
                        ->update(['product_variant_id' => $existingVariantId, 'updated_at' => $now]);

                    DB::table('stock_balances')
                        ->where('product_id', $product->id)
                        ->whereNull('product_variant_id')
                        ->update(['product_variant_id' => $existingVariantId, 'updated_at' => $now]);

                    DB::table('stock_movements')
                        ->where('product_id', $product->id)
                        ->whereNull('product_variant_id')
                        ->update(['product_variant_id' => $existingVariantId, 'updated_at' => $now]);

                    DB::table('product_images')
                        ->where('product_id', $product->id)
                        ->whereNull('product_variant_id')
                        ->update(['updated_at' => $now]);

                    $taxProfile = DB::table('tax_profiles')->where('id', $defaultTaxProfileId)->first();
                    $unit = DB::table('units')->where('id', $pieceUnitId)->first();

                    DB::table('order_items')
                        ->where('product_id', $product->id)
                        ->whereNull('product_variant_id')
                        ->orderBy('id')
                        ->chunkById(200, function ($items) use ($existingVariantId, $product, $pieceUnitId, $defaultTaxProfileId, $taxProfile, $unit, $now): void {
                            foreach ($items as $item) {
                                DB::table('order_items')
                                    ->where('id', $item->id)
                                    ->update([
                                        'product_variant_id' => $existingVariantId,
                                        'sku' => $item->sku ?: $product->sku,
                                        'unit_name' => $unit?->name,
                                        'unit_short_name' => $unit?->short_name,
                                        'base_unit_id' => $pieceUnitId,
                                        'sales_unit_id' => $pieceUnitId,
                                        'quantity_in_base_unit' => $item->quantity,
                                        'tax_profile_id' => $defaultTaxProfileId,
                                        'tax_profile_name' => $taxProfile?->name,
                                        'tax_profile_code' => $taxProfile?->code,
                                        'vat_rate' => $taxProfile?->vat_rate,
                                        'vat_amount' => 0,
                                        'is_excise_applicable' => false,
                                        'excise_rate' => null,
                                        'excise_amount' => 0,
                                        'requires_excise_stamp_entry' => false,
                                        'price_excluding_tax' => $item->unit_price ?? $item->price,
                                        'price_including_tax' => $item->price,
                                        'line_total_excluding_tax' => $item->total,
                                        'line_total_tax_amount' => 0,
                                        'line_total_including_tax' => $item->total,
                                        'updated_at' => $now,
                                    ]);
                            }
                        });
                }
            });
    }

    private function dropOrderItemColumns(): void
    {
        $columns = array_values(array_filter([
            'line_total_including_tax',
            'line_total_tax_amount',
            'line_total_excluding_tax',
            'price_including_tax',
            'price_excluding_tax',
            'requires_excise_stamp_entry',
            'excise_amount',
            'excise_rate',
            'is_excise_applicable',
            'vat_amount',
            'vat_rate',
            'tax_profile_code',
            'tax_profile_name',
            'tax_profile_id',
            'quantity_in_base_unit',
            'sales_unit_id',
            'base_unit_id',
            'unit_short_name',
            'unit_name',
            'product_variant_id',
        ], fn (string $column): bool => Schema::hasColumn('order_items', $column)));

        if ($columns !== []) {
            Schema::table('order_items', fn (Blueprint $table): Blueprint => $table->dropColumn($columns));
        }
    }

    private function dropStockMovementColumns(): void
    {
        if (Schema::hasColumn('stock_movements', 'product_variant_id')) {
            Schema::table('stock_movements', fn (Blueprint $table): Blueprint => $table->dropColumn(['product_variant_id']));
        }
    }

    private function dropStockBalanceColumns(): void
    {
        if (Schema::hasColumn('stock_balances', 'product_variant_id')) {
            Schema::table('stock_balances', fn (Blueprint $table): Blueprint => $table->dropColumn(['product_variant_id']));
        }
    }

    private function dropProductPriceColumns(): void
    {
        if (Schema::hasColumn('product_prices', 'product_variant_id')) {
            Schema::table('product_prices', fn (Blueprint $table): Blueprint => $table->dropColumn(['product_variant_id']));
        }
    }

    private function dropProductImageColumns(): void
    {
        $columns = array_values(array_filter([
            'type',
            'product_variant_id',
        ], fn (string $column): bool => Schema::hasColumn('product_images', $column)));

        if ($columns !== []) {
            Schema::table('product_images', fn (Blueprint $table): Blueprint => $table->dropColumn($columns));
        }
    }

    private function dropProductColumns(): void
    {
        $columns = array_values(array_filter([
            'sort_order',
            'is_featured',
            'status',
        ], fn (string $column): bool => Schema::hasColumn('products', $column)));

        if ($columns !== []) {
            Schema::table('products', fn (Blueprint $table): Blueprint => $table->dropColumn($columns));
        }
    }

    private function dropBrandColumns(): void
    {
        $columns = array_values(array_filter([
            'sort_order',
            'seo_description',
            'seo_title',
        ], fn (string $column): bool => Schema::hasColumn('brands', $column)));

        if ($columns !== []) {
            Schema::table('brands', fn (Blueprint $table): Blueprint => $table->dropColumn($columns));
        }
    }

    private function dropCategoryColumns(): void
    {
        $columns = array_values(array_filter([
            'default_tax_profile_id',
            'is_visible_in_menu',
            'icon',
            'banner_image',
        ], fn (string $column): bool => Schema::hasColumn('categories', $column)));

        if ($columns !== []) {
            Schema::table('categories', fn (Blueprint $table): Blueprint => $table->dropColumn($columns));
        }
    }
};