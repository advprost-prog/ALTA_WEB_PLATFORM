<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->default('openai');
            $table->boolean('enabled')->default(false);
            $table->string('mode')->default('test');
            $table->text('encrypted_api_key')->nullable();
            $table->text('encrypted_admin_api_key')->nullable();
            $table->string('model')->default('gpt-4.1-mini');
            $table->unsignedInteger('timeout')->default(60);
            $table->unsignedInteger('max_input_chars')->default(12000);
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->decimal('monthly_budget', 12, 6)->nullable();
            $table->unsignedTinyInteger('warning_threshold_percent')->default(80);
            $table->boolean('hard_limit_enabled')->default(true);
            $table->decimal('current_month_spend_estimate', 12, 6)->default(0);
            $table->string('last_health_status')->nullable();
            $table->text('last_health_message')->nullable();
            $table->timestamp('last_health_checked_at')->nullable();
            $table->timestamp('last_usage_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
