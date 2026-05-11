<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects JSON API requests whose raw body exceeds a byte limit (default 16KB).
 *
 * Prevents oversized payloads from tying up workers; does not log body contents.
 */
class RejectOversizedJsonBody
{
    public function handle(Request $request, Closure $next, string $maxBytes = '16384'): Response
    {
        $max = max(1024, (int) $maxBytes);

        $contentLength = (int) $request->header('Content-Length', 0);
        if ($contentLength > $max) {
            return $this->tooLargeResponse();
        }

        $raw = $request->getContent();
        if (strlen($raw) > $max) {
            return $this->tooLargeResponse();
        }

        return $next($request);
    }

    private function tooLargeResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Request body exceeds maximum allowed size.',
        ], 413);
    }
}
