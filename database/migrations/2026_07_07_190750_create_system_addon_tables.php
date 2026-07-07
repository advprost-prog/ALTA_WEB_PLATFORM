<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_addons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('vendor')->nullable();
            $table->string('version')->default('0.0.0');
            $table->string('source')->default('local')->index();
            $table->string('status')->default('discovered')->index();
            $table->boolean('is_installed')->default(false)->index();
            $table->boolean('is_enabled')->default(false)->index();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->string('manifest_path')->nullable();
            $table->string('service_provider')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('system_addon_settings', function (Blueprint $table) {
            $table->id();
            $table->string('addon_code')->index();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['addon_code', 'key']);
            $table->foreign('addon_code')
                ->references('code')
                ->on('system_addons')
                ->cascadeOnDelete();
        });

        Schema::create('system_addon_events', function (Blueprint $table) {
            $table->id();
            $table->string('addon_code')->nullable()->index();
            $table->string('event')->index();
            $table->string('level')->default('info')->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('addon_code')
                ->references('code')
                ->on('system_addons')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_addon_events');
        Schema::dropIfExists('system_addon_settings');
        Schema::dropIfExists('system_addons');
    }
};
