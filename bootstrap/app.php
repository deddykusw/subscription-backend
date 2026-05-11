<?php

use App\Exceptions\SubscriptionException;
use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\EnsureAdminWeb;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectOversizedJsonBody;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->redirectGuestsTo('/admin/login');

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // ── Middleware aliases ─────────────────────────────────────────────
        // NOTE: Laravel 13 has no Kernel.php. Aliases are registered here.
        $middleware->alias([
            'admin' => AdminOnly::class,
            'check.subscription' => CheckSubscription::class,
            'admin.web' => EnsureAdminWeb::class,
            'reject.oversized.json' => RejectOversizedJsonBody::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        $exceptions->render(function (SubscriptionException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], $e->getStatusCode());
            }
        });
    })->create();
