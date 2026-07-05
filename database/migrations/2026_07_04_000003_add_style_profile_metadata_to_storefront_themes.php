<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theme_generation_runs', function (Blueprint $table): void {
            $table->json('style_profile')->nullable()->after('analysis_payload');
            $table->string('selected_preset')->nullable()->after('style_profile');
            $table->json('guardrails_applied')->nullable()->after('selected_preset');
            $table->json('generation_warnings')->nullable()->after('guardrails_applied');
        });

        Schema::table('storefront_themes', function (Blueprint $table): void {
            $table->json('style_profile')->nullable()->after('style_family');
            $table->string('selected_preset')->nullable()->after('style_profile');
            $table->json('guardrails_applied')->nullable()->after('selected_preset');
            $table->json('generation_warnings')->nullable()->after('guardrails_applied');
        });

        Schema::table('storefront_theme_versions', function (Blueprint $table): void {
            $table->json('style_profile')->nullable()->after('component_config');
            $table->string('selected_preset')->nullable()->after('style_profile');
            $table->json('guardrails_applied')->nullable()->after('selected_preset');
            $table->json('generation_warnings')->nullable()->after('guardrails_applied');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_theme_versions', function (Blueprint $table): void {
            $table->dropColumn(['style_profile', 'selected_preset', 'guardrails_applied', 'generation_warnings']);
        });

        Schema::table('storefront_themes', function (Blueprint $table): void {
            $table->dropColumn(['style_profile', 'selected_preset', 'guardrails_applied', 'generation_warnings']);
        });

        Schema::table('theme_generation_runs', function (Blueprint $table): void {
            $table->dropColumn(['style_profile', 'selected_preset', 'guardrails_applied', 'generation_warnings']);
        });
    }
};
