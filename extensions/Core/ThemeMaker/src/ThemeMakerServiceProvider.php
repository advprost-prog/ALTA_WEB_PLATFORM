<?php

namespace Extensions\Core\ThemeMaker;

use Illuminate\Support\ServiceProvider;

/**
 * Demo / control service provider for the Theme Maker extension.
 *
 * This provider exists only to prove the marketplace lifecycle end-to-end:
 * it boots exclusively when the addon is enabled, and registers a minimal
 * container marker so tests can confirm the boot behavior. It performs no
 * HTTP requests, writes nothing to the database on boot, and contains no
 * business logic for actual theme generation.
 */
class ThemeMakerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        app()->instance('core.theme-maker.booted', true);
    }
}
