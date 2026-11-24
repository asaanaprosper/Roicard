<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Profile;
use App\Models\SignatureCard;
use App\Models\User;
use App\Services\SignatureCardService;
use App\Support\PlanLimitKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase6SignatureCardTest extends TestCase
{
    use RefreshDatabase;

    protected function using(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function profile(User $user, array $attributes = []): Profile
    {
        return $user->profiles()->create(array_merge([
            'slug' => 'signature-' . $user->id . '-' . uniqid(),
            'title' => 'Product Designer',
            'organisation' => 'Studio Roicard',
            'location' => 'Accra, Ghana',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
        ], $attributes));
    }

    private function planFor(User $user, ?int $templates = 1): Plan
    {
        $plan = Plan::create([
            'key' => 'sig-' . uniqid(),
            'name' => 'Signature Plan',
            'is_active' => true,
        ]);
        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'limit_key' => PlanLimitKey::SIGNATURE_CARD_TEMPLATES,
            'limit_value' => $templates,
        ]);
        $user->forceFill(['plan_id' => $plan->id])->save();

        return $plan;
    }

    public function test_all_required_formats_generate_cached_png_images_with_alt_text(): void
    {
        $user = $this->member();
        $this->planFor($user);
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;

        foreach ([
            'square' => [1080, 1080],
            'story' => [1080, 1920],
            'email_banner' => [600, 200],
        ] as $format => [$width, $height]) {
            $response = $this->using($token)
                ->postJson("/api/profiles/{$profile->id}/signature-cards/{$format}")
                ->assertOk()
                ->assertJsonPath('card.width', $width)
                ->assertJsonPath('card.height', $height);

            $this->assertNotEmpty($response->json('card.alt_text'));
            $this->assertStringContainsString('QR code opens', $response->json('card.alt_text'));

            $card = SignatureCard::where('profile_id', $profile->id)->where('format', $format)->firstOrFail();
            $path = Storage::disk('local')->path($card->file_path);
            [$actualWidth, $actualHeight] = getimagesize($path);
            $this->assertSame($width, $actualWidth);
            $this->assertSame($height, $actualHeight);

            $url = $response->json('card.preview_url');
            $parts = parse_url($url);
            $signedPath = $parts['path'] . '?' . ($parts['query'] ?? '');
            $this->get($signedPath)->assertOk()->assertHeader('Content-Type', 'image/png');
        }
    }

    public function test_signature_cards_are_entitlement_gated(): void
    {
        $user = $this->member();
        $this->planFor($user, 0);
        $profile = $this->profile($user);

        $this->using($user->createToken('test')->plainTextToken)
            ->postJson("/api/profiles/{$profile->id}/signature-cards/square")
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'SIGNATURE_CARD_TEMPLATES')
            ->assertJsonPath('upgrade.url', '/dashboard/plan');
    }

    public function test_personal_and_business_profiles_generate_and_contact_privacy_is_respected(): void
    {
        $user = $this->member();
        $this->planFor($user);
        $token = $user->createToken('test')->plainTextToken;

        $personal = $this->profile($user, [
            'contact_email' => 'private@example.com',
            'phone' => '+233500000000',
            'contact_details_visibility' => 'hidden',
            'contact_visibility' => ['email' => 'hidden', 'phone' => 'hidden', 'website' => 'hidden', 'whatsapp' => 'hidden'],
        ]);

        $business = $this->profile($user, [
            'slug' => 'signature-business-' . $user->id,
            'profile_type' => 'business',
            'display_name' => 'Acme Studio',
            'contact_email' => 'private@example.com',
            'contact_details_visibility' => 'public',
            'contact_visibility' => ['email' => 'public', 'phone' => 'public', 'website' => 'public', 'whatsapp' => 'public'],
        ]);
        $business->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))->toMediaCollection('logo');
        $business->addMedia(UploadedFile::fake()->image('cover.jpg', 800, 400))->toMediaCollection('cover');

        $this->using($token)
            ->postJson("/api/profiles/{$personal->id}/signature-cards/square")
            ->assertOk();
        $this->using($token)
            ->postJson("/api/profiles/{$business->id}/signature-cards/email_banner")
            ->assertOk();

        $service = app(SignatureCardService::class);
        $private = $service->snapshot($personal->fresh(), 'square', 'classic');
        $this->assertNull($private['email']);
        $this->assertNull($private['phone']);

        $public = $service->snapshot($business->fresh(), 'email_banner', 'classic');
        $this->assertSame('private@example.com', $public['email']);
        $this->assertNotEmpty($public['logo_path']);
    }

    public function test_referral_signature_setting_changes_qr_attribution_and_invalidates_cards(): void
    {
        $user = $this->member();
        $this->planFor($user);
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;

        $first = $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/signature-cards/square")
            ->assertOk();
        $hashBefore = SignatureCard::where('profile_id', $profile->id)->firstOrFail()->hash;
        $this->assertStringNotContainsString('ref=', $first->json('card.preview_url'));

        $this->using($token)
            ->patchJson("/api/profiles/{$profile->id}/signature-cards/settings", [
                'referral_signature_enabled' => true,
            ])
            ->assertOk();

        $this->assertSame(0, SignatureCard::where('profile_id', $profile->id)->count());

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/signature-cards/square")
            ->assertOk();

        $hashAfter = SignatureCard::where('profile_id', $profile->id)->firstOrFail()->hash;
        $this->assertNotSame($hashBefore, $hashAfter);

        $snapshot = app(SignatureCardService::class)->snapshot($profile->fresh(), 'square', 'classic');
        $this->assertNotNull($snapshot['referral_code']);
        $this->assertStringContainsString('ref=', $snapshot['scan_url']);
    }
}
