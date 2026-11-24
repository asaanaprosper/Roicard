<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider-independent WhatsApp Business adapter.
 *
 * Nothing is sent until an approved endpoint, bearer token and message
 * template are configured. The payload contains only the recipient number and
 * the configured template plus caller-supplied parameters; no free-form copy
 * is invented in code.
 */
class WhatsAppDeliveryService
{
    public function configured(): bool
    {
        return filled(config('services.whatsapp.endpoint'))
            && filled(config('services.whatsapp.token'))
            && filled(config('services.whatsapp.template'));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function send(User $user, array $parameters = []): bool
    {
        $phone = $this->phoneFor($user);

        if ($phone === null) {
            return false;
        }

        if (! $this->configured()) {
            Log::debug('whatsapp.delivery_skipped_unconfigured', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        try {
            $response = Http::withToken((string) config('services.whatsapp.token'))
                ->timeout((int) config('services.whatsapp.timeout_seconds', 5))
                ->acceptJson()
                ->post((string) config('services.whatsapp.endpoint'), [
                    'to' => $phone,
                    'template' => (string) config('services.whatsapp.template'),
                    'parameters' => $parameters,
                ]);

            if (! $response->successful()) {
                Log::warning('whatsapp.delivery_failed', [
                    'user_id' => $user->id,
                    'status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('whatsapp.delivery_error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function phoneFor(User $user): ?string
    {
        $phone = $user->profiles()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get(['phone', 'whatsapp_phone'])
            ->flatMap(fn ($profile) => [$profile->whatsapp_phone, $profile->phone])
            ->first(fn ($value) => is_string($value) && trim($value) !== '');

        if (! is_string($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 7 ? $digits : null;
    }
}
