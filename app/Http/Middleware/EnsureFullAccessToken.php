<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevents the short-lived 2FA-pending token from acting as a full session on
 * general authenticated routes. It may only be exchanged at the verify endpoint
 * (or revoked at logout).
 */
class EnsureFullAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        if ($token->can('*')) {
            return $next($request);
        }

        $allowed = [
            'api/auth/two-factor/verify',
            'api/auth/logout',
        ];

        if (in_array($request->path(), $allowed, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Complete two-factor verification to continue.',
            'error' => 'two_factor_required',
        ], 403);
    }
}
