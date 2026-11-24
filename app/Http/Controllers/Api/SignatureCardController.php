<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\SignatureCard;
use App\Services\EntitlementService;
use App\Services\SignatureCardService;
use App\Support\PlanLimitKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignatureCardController extends Controller
{
    public function __construct(
        protected SignatureCardService $cards,
        protected EntitlementService $entitlements,
        protected \App\Services\ProfileVisibilityService $visibility,
    ) {}

    public function index(Request $request, Profile $profile): JsonResponse
    {
        $this->assertOwns($request, $profile);

        return response()->json($this->metadata($profile));
    }

    public function generate(Request $request, Profile $profile, string $format): JsonResponse
    {
        $this->assertOwns($request, $profile);
        $this->cards->assertFormat($format);

        $card = $this->cards->generate($profile, $format);

        app(\App\Services\AnalyticsService::class)->record($profile->user_id, 'signature_card_generated', [
            'profile_id' => $profile->id,
            'format' => $format,
            'template' => $card->template,
        ]);

        return response()->json([
            'card' => $this->cardPayload($profile, $card),
            'message' => 'Signature card generated.',
        ]);
    }

    public function settings(Request $request, Profile $profile): JsonResponse
    {
        $this->assertOwns($request, $profile);

        $validated = $request->validate([
            'referral_signature_enabled' => ['required', 'boolean'],
        ]);

        $profile->user->forceFill([
            'referral_signature_enabled' => $validated['referral_signature_enabled'],
        ])->save();

        // Existing images contain the old QR attribution setting.
        SignatureCard::where('profile_id', $profile->id)->delete();

        return response()->json([
            'settings' => $this->settingsPayload($profile),
            'message' => 'Signature card settings updated. Cards will regenerate on next preview.',
        ]);
    }

    public function image(Profile $profile, string $format, string $version): StreamedResponse
    {
        abort_unless(
            $profile->is_live
            && $profile->is_published
            && $this->visibility->canView(null, $profile),
            403
        );

        $card = SignatureCard::where('profile_id', $profile->id)
            ->where('format', $format)
            ->where('hash', $version)
            ->firstOrFail();

        abort_unless(Storage::disk('local')->exists($card->file_path), 404);

        return Storage::disk('local')->response($card->file_path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadata(Profile $profile): array
    {
        $limit = $this->entitlements->limit($profile->user, PlanLimitKey::SIGNATURE_CARD_TEMPLATES);
        $implemented = count(SignatureCard::templates());

        return [
            'profile' => [
                'id' => $profile->id,
                'slug' => $profile->slug,
                'display_name' => $profile->display_name,
                'profile_type' => $profile->profile_type,
            ],
            'enabled' => $limit === null || $limit > 0,
            'limit' => $limit,
            'implemented_templates' => $implemented,
            'templates' => SignatureCard::templates(),
            'formats' => collect(SignatureCard::formats())->map(fn ($format) => [
                'key' => $format,
                'label' => match ($format) {
                    SignatureCard::FORMAT_STORY => 'Story',
                    SignatureCard::FORMAT_EMAIL_BANNER => 'Email banner',
                    default => 'Square',
                },
                'width' => SignatureCard::dimensions($format)[0],
                'height' => SignatureCard::dimensions($format)[1],
            ])->values()->all(),
            'existing' => SignatureCard::where('profile_id', $profile->id)
                ->get()
                ->map(fn (SignatureCard $card) => $this->cardPayload($profile, $card))
                ->values()
                ->all(),
            'settings' => $this->settingsPayload($profile),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function cardPayload(Profile $profile, SignatureCard $card): array
    {
        return [
            'id' => $card->id,
            'format' => $card->format,
            'template' => $card->template,
            'width' => SignatureCard::dimensions($card->format)[0],
            'height' => SignatureCard::dimensions($card->format)[1],
            'alt_text' => $card->alt_text,
            'generated_at' => $card->generated_at,
            'preview_url' => URL::temporarySignedRoute(
                'signature-cards.image',
                now()->addMinutes(15),
                ['profile' => $profile->id, 'format' => $card->format, 'version' => $card->hash],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function settingsPayload(Profile $profile): array
    {
        return [
            'referral_signature_enabled' => (bool) $profile->user->referral_signature_enabled,
            'referral_code' => $profile->user->referral_code,
        ];
    }

    protected function assertOwns(Request $request, Profile $profile): void
    {
        abort_unless((int) $profile->user_id === (int) $request->user()->id, 403);
    }
}
