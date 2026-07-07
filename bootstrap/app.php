<?php

use App\Console\Commands\Addons\DisableAddon;
use App\Console\Commands\Addons\DiscoverAddons;
use App\Console\Commands\Addons\DoctorAddons;
use App\Console\Commands\Addons\EnableAddon;
use App\Console\Commands\Addons\InstallAddon;
use App\Console\Commands\Addons\ListAddons;
use App\Console\Commands\Addons\UninstallAddon;
use App\Console\Commands\AdminGovernanceCheck;
use App\Console\Commands\AiHealth;
use App\Console\Commands\BackfillCustomersFromOrders;
use App\Console\Commands\CommerceHealthCheck;
use App\Console\Commands\DiagnoseProductImages;
use App\Console\Commands\ResetAdminAccess;
use App\Console\Commands\SendPendingNotifications;
use App\Console\Commands\TestNotificationEmail;
use App\Console\Commands\TestProductImageImport;
use App\Console\Commands\TestStorage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        AdminGovernanceCheck::class,
        DisableAddon::class,
        DiscoverAddons::class,
        DoctorAddons::class,
        EnableAddon::class,
        InstallAddon::class,
        ListAddons::class,
        UninstallAddon::class,
        AiHealth::class,
        BackfillCustomersFromOrders::class,
        CommerceHealthCheck::class,
        DiagnoseProductImages::class,
        ResetAdminAccess::class,
        SendPendingNotifications::class,
        TestNotificationEmail::class,
        TestProductImageImport::class,
        TestStorage::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
