<?php

use App\Console\Commands\AdminGovernanceCheck;
use App\Console\Commands\AiHealth;
use App\Console\Commands\DiagnoseProductImages;
use App\Console\Commands\ResetAdminAccess;
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
        AiHealth::class,
        DiagnoseProductImages::class,
        ResetAdminAccess::class,
        TestProductImageImport::class,
        TestStorage::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
