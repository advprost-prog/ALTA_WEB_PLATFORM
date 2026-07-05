<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->text('source_url')->nullable()->after('sort_order');
            $table->string('source_domain')->nullable()->after('source_url');
            $table->foreignId('imported_by')->nullable()->after('source_domain')->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable()->after('imported_by');
            $table->unsignedTinyInteger('quality_score')->nullable()->after('imported_at');
            $table->json('metadata')->nullable()->after('quality_score');
            $table->boolean('is_main')->default(false)->index()->after('metadata');
            $table->string('file_hash', 64)->nullable()->index()->after('is_main');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('imported_by');
            $table->dropColumn([
                'source_url',
                'source_domain',
                'imported_at',
                'quality_score',
                'metadata',
                'is_main',
                'file_hash',
            ]);
        });
    }
};
