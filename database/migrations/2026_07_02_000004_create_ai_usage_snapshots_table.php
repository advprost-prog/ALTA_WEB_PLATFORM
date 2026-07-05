<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('provider')->default('openai');
            $table->string('currency')->nullable();
            $table->decimal('cost_value', 12, 6)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index(['provider', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_snapshots');
    }
};
