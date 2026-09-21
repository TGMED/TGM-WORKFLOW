<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureImportIsPermitted;
use App\Http\Middleware\EnsureProfileIsComplete;
use App\Http\Middleware\EnsureUserCanApprove;
use App\Http\Middleware\EnsureUserClocksIn;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\ErrorPage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
            'import-permitted' => EnsureImportIsPermitted::class,
            'profile-complete' => EnsureProfileIsComplete::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every error a person can land on goes to the app's own error page,
        // rather than the framework's, which Inertia shows in an overlay on
        // top of whatever they were doing.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            $status = $response->getStatusCode();

            // Redirects (a failed validation, a sign-in check) are not errors,
            // and a JSON caller wants JSON.
            if ($status < 400 || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return $response;
            }

            // While developing, a server error keeps its stack trace.
            if ($status >= 500 && config('app.debug')) {
                return $response;
            }

            // A form left open past its session: send them back to it rather
            // than to an error page, since trying again is all it takes.
            if ($status === 419) {
                return back()->with('toast', [
                    'type' => 'error',
                    'message' => 'The page had been open too long, so that did not go through. Try it again.',
                ]);
            }

            return ErrorPage::render($request, $e, $status);
        });
    })->create();
