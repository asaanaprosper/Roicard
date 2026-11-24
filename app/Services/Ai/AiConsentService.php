<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class AiConsentService
{
    public function version(): string
    {
        return (string) config('roicard.ai.consent_version', '2026-11');
    }

    public function text(): string
    {
        return (string) config('roicard.ai.consent_text');
    }

    public function hasConsent(User $user): bool
    {
        return $user->ai_consent_at !== null
            && (string) $user->ai_consent_version === $this->version();
    }

    /**
     * @throws ValidationException
     */
    public function assertConsent(User $user): void
    {
        if ($this->hasConsent($user)) {
            return;
        }

        throw ValidationException::withMessages([
            'consent_required' => 'Accept the AI data-processing notice before using AI features.',
        ]);
    }

    public function accept(User $user): User
    {
        $user->forceFill([
            'ai_consent_at' => now(),
            'ai_consent_version' => $this->version(),
        ])->save();

        return $user->fresh() ?? $user;
    }
}
