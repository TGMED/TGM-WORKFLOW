<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureProfileIsComplete;
use App\Http\Middleware\EnsureUserCanApprove;
use App\Http\Middleware\EnsureUserClocksIn;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'super-admin' => EnsureUserIsSuperAdmin::class,
            'active' => EnsureAccountIsActive::class,
            'clocks-in' => EnsureUserClocksIn::class,
            'approver' => EnsureUserCanApprove::class,
            'profile-complete' => EnsureProfileIsComplete::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
