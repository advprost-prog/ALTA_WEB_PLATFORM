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
        Schema::table('banners', function (Blueprint $table) {
            $table->string('eyebrow')->nullable();
            $table->string('secondary_button_text')->nullable();
            $table->string('secondary_button_url')->nullable();
            $table->string('mobile_image')->nullable();
            $table->string('style_preset')->default('dark_overlay')->index();
            $table->string('layout_variant')->default('background_image');
            $table->string('visual_style')->default('clean');
            $table->string('color_scheme')->default('auto');
            $table->string('text_align')->default('left');
            $table->string('content_position')->default('left');
            $table->string('vertical_align')->default('center');
            $table->boolean('overlay_enabled')->default(true);
            $table->unsignedTinyInteger('overlay_opacity')->default(30);
            $table->string('overlay_style')->default('dark');
            $table->string('background_color')->nullable();
            $table->string('text_color')->nullable();
            $table->string('button_style')->default('primary');
            $table->string('border_radius')->default('md');
            $table->string('shadow')->default('md');
            $table->string('height_variant')->default('md');
            $table->string('image_fit')->default('cover');
            $table->string('image_position')->default('center');
            $table->boolean('animation_enabled')->default(false);
            $table->string('animation_type')->default('none');
            $table->unsignedInteger('animation_delay_ms')->default(0);
            $table->unsignedInteger('animation_duration_ms')->default(500);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn([
                'eyebrow',
                'secondary_button_text',
                'secondary_button_url',
                'mobile_image',
                'style_preset',
                'layout_variant',
                'visual_style',
                'color_scheme',
                'text_align',
                'content_position',
                'vertical_align',
                'overlay_enabled',
                'overlay_opacity',
                'overlay_style',
                'background_color',
                'text_color',
                'button_style',
                'border_radius',
                'shadow',
                'height_variant',
                'image_fit',
                'image_position',
                'animation_enabled',
                'animation_type',
                'animation_delay_ms',
                'animation_duration_ms',
            ]);
        });
    }
};
