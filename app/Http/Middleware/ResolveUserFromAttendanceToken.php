<?php

namespace App\Http\Middleware;

use App\Services\ExternalAuthService;
use App\Support\RequestAttendanceToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ResolveUserFromAttendanceToken
{
    public function __construct(
        private readonly ExternalAuthService $externalAuthService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = RequestAttendanceToken::from($request);

        if ($token === null || $token === '') {
            return response()->json([
                'success' => false,
                'message' => 'attendance_token is required.',
                'errors' => [
                    'attendance_token' => ['The attendance token field is required.'],
                ],
            ], 422);
        }

        if (strlen($token) > 8192) {
            return response()->json([
                'success' => false,
                'message' => 'attendance_token is invalid.',
                'errors' => [
                    'attendance_token' => ['The attendance token may not be greater than 8192 characters.'],
                ],
            ], 422);
        }

        try {
            $user = $this->externalAuthService->resolveLocalUserFromAttendanceToken($token);
        } catch (\RuntimeException $e) {
            Log::warning('resolve_attendance_token_failed', [
                'reason' => $e->getMessage(),
                'attendance_token_prefix' => $this->maskToken($token),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 401);
        }

        $request->attributes->set('subscription_user', $user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }

    private function maskToken(string $token): string
    {
        $len = strlen($token);
        if ($len <= 12) {
            return '***';
        }

        return substr($token, 0, 4).'…'.substr($token, -4)." (len={$len})";
    }
}
