<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safe retry protection for mutating endpoints.
 *
 * The cache key is scoped to the caller, HTTP method, path, client key and
 * request-body hash. This prevents one member from replaying or blocking
 * another member's idempotent request by guessing an Idempotency-Key.
 */
class IdempotencyMiddleware
{
    private const RESULT_TTL_HOURS = 24;

    private const PROCESSING_TTL_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || strlen($key) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            return response()->json(['message' => 'A valid Idempotency-Key header is required'], 400);
        }

        $actor = auth()->id() ? 'user:' . auth()->id() : 'ip:' . $request->ip();
        $bodyHash = hash('sha256', $request->getContent());
        $cacheKey = sprintf(
            'idempotency:%s:%s:%s:%s:%s:%s',
            $actor,
            strtoupper($request->method()),
            $request->path(),
            (string) $request->getQueryString(),
            $key,
            $bodyHash,
        );

        if (! Cache::add($cacheKey, ['__processing__' => true], now()->addMinutes(self::PROCESSING_TTL_MINUTES))) {
            $cached = Cache::get($cacheKey);

            if (! is_array($cached) || isset($cached['__processing__'])) {
                return response()->json(['message' => 'Request is already being processed'], 409);
            }

            return response()->json(
                $cached['data'] ?? null,
                (int) ($cached['status'] ?? 200),
                ['X-Idempotent-Replayed' => 'true'],
            );
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            Cache::put($cacheKey, [
                'data' => json_decode($response->getContent(), true),
                'status' => $response->getStatusCode(),
            ], now()->addHours(self::RESULT_TTL_HOURS));
        } else {
            Cache::forget($cacheKey);
        }

        return $response;
    }
}
