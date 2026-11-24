<?php

namespace App\Services\Notifications;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider-independent push adapter.
 *
 * Device tokens are stored locally, but delivering to APNs/FCM/Web Push needs
 * an approved push provider. The adapter posts the token batch to a configured
 * generic webhook; until that endpoint and bearer token exist, push is skipped
 * and logged without member PII.
 */
class PushDeliveryService
{
    public function configured(): bool
    {
        return config('services.push.driver') === 'webhook'
            && filled(config('services.push.endpoint'))
            && filled(config('services.push.token'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(User $user, array $payload): bool
    {
        $tokens = PushToken::where('user_id', $user->id)->pluck('token')->all();

        if ($tokens === []) {
            return false;
        }

        if (! $this->configured()) {
            Log::debug('push.delivery_skipped_unconfigured', [
                'user_id' => $user->id,
                'type' => $payload['type'] ?? null,
                'token_count' => count($tokens),
            ]);

            return false;
        }

        try {
            $response = Http::withToken((string) config('services.push.token'))
                ->timeout((int) config('services.push.timeout_seconds', 5))
                ->acceptJson()
                ->post((string) config('services.push.endpoint'), [
                    'tokens' => $tokens,
                    'notification' => $payload,
                ]);

            if (! $response->successful()) {
                Log::warning('push.delivery_failed', [
                    'user_id' => $user->id,
                    'status' => $response->status(),
                    'type' => $payload['type'] ?? null,
                ]);

                return false;
            }

            PushToken::whereIn('token', $tokens)->update(['last_used_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            // Push is best-effort; in-app and email delivery must not fail.
            Log::warning('push.delivery_error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
