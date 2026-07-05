<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('imported_product_image_id')->nullable()->constrained('product_images')->nullOnDelete();
            $table->string('provider')->index();
            $table->text('source_url');
            $table->text('thumbnail_url')->nullable();
            $table->text('image_url');
            $table->string('source_domain')->nullable()->index();
            $table->string('title')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->string('status')->default('pending')->index();
            $table->boolean('can_import')->default(false)->index();
            $table->json('warnings')->nullable();
            $table->text('license_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_candidates');
    }
};
