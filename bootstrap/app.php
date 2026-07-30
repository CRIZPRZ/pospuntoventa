<?php

use App\Http\Middleware\CheckTrial;
use App\Http\Middleware\ResolveSucursal;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SuperAdminMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', ResolveTenant::class);
        $middleware->appendToGroup('api', ResolveSucursal::class);
        $middleware->alias([
            'superadmin'  => SuperAdminMiddleware::class,
            'check.trial' => CheckTrial::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
