<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Resolves an optional signed-in viewer for public routes without letting a
 * short-lived two-factor-pending token act as a full session.
 */
final class BearerViewer
{
    public static function resolve(Request $request, string $ability = 'profile:read'): ?User
    {
        $plainTextToken = $request->bearerToken();

        if (! $plainTextToken) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($plainTextToken);

        if (! $accessToken || ! $accessToken->tokenable) {
            return null;
        }

        if (! $accessToken->can($ability)) {
            return null;
        }

        return $accessToken->tokenable;
    }
}
