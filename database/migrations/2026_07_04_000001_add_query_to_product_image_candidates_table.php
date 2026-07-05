<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_image_candidates', 'query')) {
            Schema::table('product_image_candidates', function (Blueprint $table): void {
                $table->text('query')->nullable()->after('provider');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_image_candidates', 'query')) {
            Schema::table('product_image_candidates', function (Blueprint $table): void {
                $table->dropColumn('query');
            });
        }
    }
};
