<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureProfileIsComplete;
use App\Http\Middleware\EnsureUserCanApprove;
use App\Http\Middleware\EnsureUserClocksIn;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // AddLinkHeadersForPreloadedAssets, which the starter kit appends here,
        // is deliberately left out. It repeats Vite's whole modulepreload graph
        // as a Link response header on every request. Vite already writes the
        // same hints as <link rel="modulepreload"> tags in the head, so the
        // header buys nothing, and on a page with a wide component tree it grew
        // past nginx's FastCGI header buffer and returned 502s. Do not re-add it
        // without moving to 103 Early Hints at the edge.
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'super-admin' => EnsureUserIsSuperAdmin::class,
            'active' => EnsureAccountIsActive::class,
            'clocks-in' => EnsureUserClocksIn::class,
            'approver' => EnsureUserCanApprove::class,
            'permission' => EnsureUserHasPermission::class,
            'profile-complete' => EnsureProfileIsComplete::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
