<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function provider(): string
    {
        return (string) config('app.payment_provider', 'mock');
    }

    /**
     * Mock is only a test/local lifecycle simulator. Live providers require
     * their existing credentials; missing configuration must never silently
     * fall back to mock in production.
     */
    public function configured(): bool
    {
        $provider = $this->provider();

        return match ($provider) {
            'paystack' => filled(config('services.paystack.secret_key')),
            'hubtel' => filled(config('services.hubtel.client_id'))
                && filled(config('services.hubtel.client_secret')),
            'mock' => app()->environment(['local', 'testing']),
            default => false,
        };
    }

    public function initiate(Payment $payment): array
    {
        $provider = config('app.payment_provider', 'mock');

        return match ($provider) {
            'paystack' => $this->initiatePaystack($payment),
            'hubtel' => $this->initiateHubtel($payment),
            default => $this->initiateMock($payment),
        };
    }

    public function verify(string $reference): ?array
    {
        $provider = config('app.payment_provider', 'mock');

        return match ($provider) {
            'paystack' => $this->verifyPaystack($reference),
            'hubtel' => $this->verifyHubtel($reference),
            default => $this->verifyMock($reference),
        };
    }

    public function verifyWebhook(string $provider, array $payload, string $signature, ?string $rawBody = null): bool
    {
        return match ($provider) {
            'paystack' => $this->verifyPaystackWebhook($payload, $signature, $rawBody),
            'hubtel' => $this->verifyHubtelWebhook($payload, $signature, $rawBody),
            'mock' => $this->configured(),
            default => false,
        };
    }

    protected function initiatePaystack(Payment $payment): array
    {
        $response = Http::withToken(config('services.paystack.secret_key'))
            ->timeout(15)
            ->connectTimeout(10)
            ->post('https://api.paystack.co/transaction/initialize', [
                'email' => $payment->user->email,
                'amount' => (int) ($payment->amount * 100),
                'currency' => $payment->currency,
                'reference' => $payment->provider_reference,
                'callback_url' => config('app.frontend_url') . '/onboarding/callback',
            ]);

        $data = $response->throw()->json();

        return [
            'authorization_url' => $data['data']['authorization_url'],
            'reference' => $data['data']['reference'],
        ];
    }

    protected function verifyPaystack(string $reference): ?array
    {
        $response = Http::withToken(config('services.paystack.secret_key'))
            ->timeout(15)
            ->connectTimeout(10)
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        $data = $response->throw()->json();

        // Map Paystack's lifecycle explicitly: an in-progress charge is NOT a
        // failure, only terminal states are.
        return [
            'status' => match ($data['data']['status'] ?? '') {
                'success' => 'success',
                'failed', 'abandoned', 'reversed' => 'failed',
                default => 'pending',
            },
            'reference' => $reference,
        ];
    }

    protected function initiateHubtel(Payment $payment): array
    {
        $response = Http::withBasicAuth(
            config('services.hubtel.client_id'),
            config('services.hubtel.client_secret')
        )->post('https://api.hubtel.com/v1/merchantaccount/merchant/onlinecheckout', [
            'totalAmount' => $payment->amount,
            'description' => match ($payment->purpose) {
                Payment::PURPOSE_SUBSCRIPTION => 'Roicard subscription',
                Payment::PURPOSE_CARD_ACTIVATION => 'Roicard Smart Card Activation',
                Payment::PURPOSE_MEMBERSHIP => 'Roicard membership activation',
                default => 'Roicard payment',
            },
            'clientReference' => $payment->provider_reference,
            'callbackUrl' => config('app.url') . '/api/payments/webhook/hubtel',
            'returnUrl' => config('app.frontend_url') . '/onboarding/callback',
            'merchantAccountNumber' => config('services.hubtel.merchant_number'),
            'channels' => ['MOMO'],
        ]);

        $data = $response->throw()->json();

        return [
            'checkout_url' => $data['data']['checkoutUrl'] ?? null,
            'reference' => $data['data']['checkoutId'] ?? $payment->provider_reference,
        ];
    }

    protected function verifyHubtel(string $reference): ?array
    {
        $response = Http::withBasicAuth(
            config('services.hubtel.client_id'),
            config('services.hubtel.client_secret')
        )->get("https://api.hubtel.com/v1/merchantaccount/merchant/onlinecheckout/status/{$reference}");

        $data = $response->throw()->json();

        return [
            'status' => match ($data['data']['status'] ?? '') {
                'Success' => 'success',
                'Failure' => 'failed',
                default => 'pending',
            },
            'reference' => $reference,
        ];
    }

    protected function verifyPaystackWebhook(array $payload, string $signature, ?string $rawBody = null): bool
    {
        $secret = (string) config('services.paystack.secret_key');
        if ($secret === '' || $signature === '') {
            return false;
        }

        $computed = hash_hmac('sha512', $rawBody ?? json_encode($payload), $secret);
        return hash_equals($computed, $signature);
    }

    protected function verifyHubtelWebhook(array $payload, string $signature, ?string $rawBody = null): bool
    {
        $secret = (string) config('services.hubtel.client_secret');
        if ($secret === '' || $signature === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $rawBody ?? json_encode($payload), $secret);
        return hash_equals($computed, $signature);
    }

    protected function initiateMock(Payment $payment): array
    {
        return [
            'authorization_url' => null,
            'reference' => $payment->provider_reference,
            'mock' => true,
            'message' => 'Mock payment initiated. Use /api/payments/status/{ref} to confirm.',
        ];
    }

    protected function verifyMock(string $reference): ?array
    {
        $payment = Payment::where('provider_reference', $reference)->first();

        if (!$payment) {
            return null;
        }

        return [
            'status' => $payment->status,
            'reference' => $reference,
        ];
    }
}
