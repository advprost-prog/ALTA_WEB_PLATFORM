<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->boolean('image_search_enabled')->default(false)->after('hard_limit_enabled');
            $table->string('image_search_provider')->default('manual_url')->after('image_search_enabled');
            $table->text('encrypted_image_search_api_key')->nullable()->after('image_search_provider');
            $table->boolean('image_search_safe_mode')->default(true)->after('encrypted_image_search_api_key');
            $table->unsignedTinyInteger('image_search_max_candidates')->default(5)->after('image_search_safe_mode');
            $table->unsignedSmallInteger('image_search_min_width')->default(600)->after('image_search_max_candidates');
            $table->unsignedSmallInteger('image_search_min_height')->default(600)->after('image_search_min_width');
            $table->string('image_search_preferred_format')->default('webp')->after('image_search_min_height');
            $table->unsignedTinyInteger('image_search_max_download_size_mb')->default(5)->after('image_search_preferred_format');
            $table->boolean('allow_manual_url_candidates')->default(true)->after('image_search_max_download_size_mb');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'image_search_enabled',
                'image_search_provider',
                'encrypted_image_search_api_key',
                'image_search_safe_mode',
                'image_search_max_candidates',
                'image_search_min_width',
                'image_search_min_height',
                'image_search_preferred_format',
                'image_search_max_download_size_mb',
                'allow_manual_url_candidates',
            ]);
        });
    }
};
