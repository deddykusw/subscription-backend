<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user has the is_admin flag set to true.
 *
 * Usage:
 *   Route::middleware('admin')->group(...)
 *
 * Registration: bootstrap/app.php → $middleware->alias(['admin' => AdminOnly::class])
 *
 * NOTE: Laravel 13 has no Kernel.php — aliases are registered in bootstrap/app.php.
 */
class AdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Administrator access is required.',
            ], 403);
        }

        return $next($request);
    }
}
