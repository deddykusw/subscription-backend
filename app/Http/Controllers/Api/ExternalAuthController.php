<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExternalAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;


class ExternalAuthController extends Controller
{
    public function __invoke()
    {
        // This method is intentionally left blank.
        // The actual logic is handled in the AuthService.
    }

    /**
     * Exchange attendance token untuk subscription status
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function exchangeToken(Request $request)
    {
        $validated = $request->validate([
            'attendance_token' => 'required|string',
            'user_data' => 'nullable|array',
            'user_data.external_user_id' => 'required_with:user_data|integer',
            'user_data.username' => 'nullable|string',
            'user_data.email' => 'nullable|email',
            'user_data.name' => 'nullable|string',
        ]);

        try {
            $externalAuthService = app(ExternalAuthService::class);

            $result = $externalAuthService->exchangeToken(
                $validated['attendance_token'],
                $validated['user_data'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Token validated successfully',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token validation failed',
                'error' => $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Quick validation: bisa akses attendance atau tidak
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validateAccess(Request $request)
    {
        $validated = $request->validate([
            'attendance_token' => 'required|string',
            'external_user_id' => 'required|integer',
        ]);

        try {
            // Find user
            $user = User::where('external_user_id', $validated['external_user_id'])
                ->firstOrFail();

            // Check last validation
            $lastValidation = $user->last_token_validation_at;
            $shouldRevalidate = !$lastValidation ||
                $lastValidation->diffInMinutes(now()) > 60;

            if ($shouldRevalidate) {
                // Validate token
                $externalAuthService = app(ExternalAuthService::class);
                $attendanceUserData = $externalAuthService->validateAttendanceToken(
                    $validated['attendance_token']
                );

                if (!$attendanceUserData) {
                    throw new \Exception('Invalid token');
                }

                // Update last validation
                $user->update(['last_token_validation_at' => now()]);
            }

            // Check access
            $accessCheck = app(ExternalAuthService::class)
                ->canAccessAttendance($user);

            return response()->json([
                'success' => true,
                'data' => $accessCheck,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [
                    'can_access' => false,
                    'reason' => 'Validation failed'
                ]
            ], 401);
        }
    }
}
