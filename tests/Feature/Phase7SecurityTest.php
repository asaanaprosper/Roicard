<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class Phase7SecurityTest extends TestCase
{
    use RefreshDatabase;

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
            'slug' => 'phase7-' . $user->id . '-' . uniqid(),
            'title' => 'Product Designer',
            'bio' => 'A public professional summary.',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
        ], $attributes));
    }

    public function test_hidden_cv_is_not_exposed_on_the_public_profile(): void
    {
        $owner = $this->member();
        $hidden = $this->profile($owner, ['slug' => 'cv-hidden-' . $owner->id]);
        $hidden->addMedia(UploadedFile::fake()->create('private-cv.pdf', 80, 'application/pdf'))
            ->withCustomProperties(['visible' => false])
            ->toMediaCollection('cv');

        $this->getJson('/api/public/' . $hidden->slug . '?_metadata=1')
            ->assertOk()
            ->assertJsonPath('cv', null);

        $visible = $this->profile($owner, ['slug' => 'cv-visible-' . $owner->id]);
        $visible->addMedia(UploadedFile::fake()->create('public-cv.pdf', 80, 'application/pdf'))
            ->withCustomProperties(['visible' => true])
            ->toMediaCollection('cv');

        $response = $this->getJson('/api/public/' . $visible->slug . '?_metadata=1')->assertOk();
        $this->assertNotNull($response->json('cv.url'));
    }

    public function test_public_metadata_is_profile_specific_private_safe_and_cache_invalidates(): void
    {
        $firstUser = $this->member();
        $first = $this->profile($firstUser, [
            'slug' => 'metadata-first',
            'display_name' => 'First Profile Name',
            'bio' => 'First profile biography.',
        ]);

        $secondUser = $this->member();
        $second = $this->profile($secondUser, [
            'slug' => 'metadata-second',
            'display_name' => 'Second Profile Name',
            'bio' => 'Second profile biography.',
        ]);

        $privateUser = $this->member();
        $private = $this->profile($privateUser, [
            'slug' => 'metadata-private',
            'visibility' => 'private',
            'is_searchable' => false,
        ]);

        $firstResponse = $this->getJson('/api/public/' . $first->slug . '?_metadata=1')->assertOk();
        $secondResponse = $this->getJson('/api/public/' . $second->slug . '?_metadata=1')->assertOk();

        $this->assertSame('First Profile Name', $firstResponse->json('display_name'));
        $this->assertSame('Second Profile Name', $secondResponse->json('display_name'));
        $this->assertNotSame($firstResponse->json('bio'), $secondResponse->json('bio'));

        $this->getJson('/api/public/' . $private->slug . '?_metadata=1')->assertStatus(403);

        $first->forceFill(['display_name' => 'Updated Profile Name'])->save();
        $this->getJson('/api/public/' . $first->slug . '?_metadata=1')
            ->assertOk()
            ->assertJsonPath('display_name', 'Updated Profile Name');
    }

    public function test_signature_qr_records_signature_source_and_referral_attribution(): void
    {
        $owner = $this->member(['referral_code' => 'PILOTCODE']);
        $profile = $this->profile($owner, [
            'slug' => 'signature-scan-target',
            'referral_signature_enabled' => true,
        ]);
        $profile->user->forceFill(['referral_signature_enabled' => true])->save();

        $response = $this->get('/api/qr/' . $profile->slug . '?source=signature&ref=PILOTCODE');

        $response->assertRedirect(config('app.frontend_url') . '/signature-scan-target?src=signature');

        $event = AnalyticsEvent::where('member_id', $owner->id)
            ->where('type', 'signature_scan')
            ->firstOrFail();

        $this->assertSame('signature', $event->metadata['source']);
        $this->assertSame('PILOTCODE', $event->metadata['referral_code']);
    }

    public function test_admin_metrics_require_admin_and_report_recorded_events_only(): void
    {
        $admin = $this->member(['role' => 'admin']);
        $owner = $this->member();
        $profile = $this->profile($owner);

        $this->getJson('/api/public/' . $profile->slug . '?src=search')->assertOk();
        $this->getJson('/api/admin/metrics')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $response = $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson('/api/admin/metrics?days=30')
            ->assertOk();

        $this->assertSame(1, $response->json('discovery.profile_views'));
        $this->assertSame('search', $response->json('discovery.sources.0.source'));
        $this->assertSame(0, $response->json('discovery.searches'));
    }

    public function test_organization_service_search_uses_portable_cast_query(): void
    {
        $owner = $this->member();
        \App\Models\Community::create([
            'owner_user_id' => $owner->id,
            'name' => 'Pilot Organization',
            'slug' => 'pilot-organization',
            'type' => \App\Models\Community::TYPE_ORGANIZATION,
            'join_method' => \App\Models\Community::JOIN_REQUEST,
            'status' => \App\Models\Community::STATUS_ACTIVE,
            'verification_status' => \App\Models\Community::VERIFICATION_VERIFIED,
            'services' => ['Brand strategy', 'Research'],
        ]);

        $this->getJson('/api/organizations?q=Brand')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'pilot-organization']);
    }
}
