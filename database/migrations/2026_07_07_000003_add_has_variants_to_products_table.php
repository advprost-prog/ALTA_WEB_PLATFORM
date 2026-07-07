<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = Schema::getColumnListing('products');

        if (! in_array('has_variants', $columns, true)) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('has_variants')->default(false)->after('sort_order')->index();
            });
        }

        DB::table('products')
            ->whereNull('has_variants')
            ->update(['has_variants' => false]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'has_variants')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('has_variants');
        });
    }
};
