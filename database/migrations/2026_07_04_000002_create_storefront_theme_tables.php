<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_generation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('source_url');
            $table->string('status')->default('pending')->index();
            $table->json('input_payload')->nullable();
            $table->json('analysis_payload')->nullable();
            $table->json('generated_theme_payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('storefront_themes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type')->default('custom')->index();
            $table->string('status')->default('draft')->index();
            $table->boolean('is_active')->default(false)->index();
            $table->string('source')->nullable();
            $table->text('source_url')->nullable();
            $table->string('style_family')->nullable();
            $table->json('tokens');
            $table->json('layout_config');
            $table->json('component_config')->nullable();
            $table->json('css_variables')->nullable();
            $table->longText('custom_css')->nullable();
            $table->string('preview_image')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('generated_by_ai')->default(false)->index();
            $table->foreignId('ai_run_id')->nullable()->constrained('theme_generation_runs')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('storefront_theme_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_theme_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('tokens');
            $table->json('layout_config');
            $table->json('component_config')->nullable();
            $table->json('css_variables')->nullable();
            $table->longText('custom_css')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['storefront_theme_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_theme_versions');
        Schema::dropIfExists('storefront_themes');
        Schema::dropIfExists('theme_generation_runs');
    }
};
