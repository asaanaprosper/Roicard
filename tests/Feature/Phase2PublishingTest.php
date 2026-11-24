<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2PublishingTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['status' => 'active'], $attributes));
    }

    private function publishedProfile(User $user, array $attributes = []): Profile
    {
        $profile = $user->profiles()->create(array_merge([
            'slug' => 'poster-' . $user->id,
            'title' => 'Product Designer',
            'bio' => 'Designing useful things.',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
        ], $attributes));

        $profile->forceFill([
            'is_published' => true,
            'published_at' => now(),
            'is_live' => true,
        ])->save();

        return $profile->fresh();
    }

    public function test_publish_requires_name_headline_and_explicit_privacy(): void
    {
        $user = $this->member();
        $profile = $user->profiles()->create(['slug' => 'unfinished']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'privacy']);

        $this->withToken($token)
            ->putJson("/api/profiles/{$profile->id}", [
                'title' => 'Independent Consultant',
                'visibility' => 'public',
                'confirm_privacy' => true,
            ])
            ->assertOk();

        $response = $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/publish")
            ->assertOk();

        $response->assertJsonPath('profile.is_published', true);
        $this->assertNotNull($profile->fresh()->published_at);
        $this->assertTrue($profile->fresh()->is_live);

        $this->withToken($token)
            ->getJson('/api/onboarding')
            ->assertOk()
            ->assertJsonPath('required_complete', true);
    }

    public function test_unpublished_profiles_are_owner_only_and_republish_restores_access(): void
    {
        $user = $this->member();
        $profile = $user->profiles()->create([
            'slug' => 'owner-only',
            'title' => 'Engineer',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->flushHeaders()
            ->getJson('/api/public/owner-only')
            ->assertStatus(404);

        $this->withToken($token)
            ->getJson("/api/profiles/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('profile.id', $profile->id);

        // The owner can preview an unpublished draft at its public URL.
        $this->withToken($token)
            ->getJson('/api/public/owner-only?_metadata=1')
            ->assertOk()
            ->assertJsonPath('is_published', false);

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/publish")
            ->assertOk()
            ->assertJsonPath('profile.is_published', true);

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/unpublish")
            ->assertOk()
            ->assertJsonPath('profile.is_published', false);
    }

    public function test_ai_generated_fields_require_individual_approval_before_publishing(): void
    {
        $user = $this->member();
        $profile = $this->publishedProfile($user, [
            'slug' => 'ai-profile',
            'title' => 'Existing headline',
            'bio' => 'Existing member text',
            'ai_draft' => [
                'source' => 'ai',
                'headline' => 'AI Generated Headline',
                'about' => 'AI generated about text',
            ],
            'ai_approved_fields' => [],
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ai_draft']);

        $this->withToken($token)
            ->patchJson("/api/profiles/{$profile->id}/ai-draft/approve", ['fields' => ['headline']])
            ->assertOk();

        // One approved field is not enough while another generated field remains.
        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ai_draft']);

        $this->withToken($token)
            ->patchJson("/api/profiles/{$profile->id}/ai-draft/approve", ['fields' => ['about']])
            ->assertOk();

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/publish")
            ->assertOk();

        $profile->refresh();
        $this->assertSame('AI Generated Headline', $profile->title);
        $this->assertSame('AI generated about text', $profile->bio);
    }

    public function test_contact_visibility_is_enforced_per_field_on_the_public_profile(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $profile = $this->publishedProfile($owner, [
            'slug' => 'contact-fields',
            'phone' => '+233500000001',
            'whatsapp_phone' => '+233500000002',
            'contact_email' => 'public-contact@example.com',
            'website_url' => 'https://example.com',
            'contact_visibility' => [
                'phone' => 'public',
                'whatsapp' => 'hidden',
                'email' => 'connections',
                'website' => 'public',
            ],
        ]);
        $token = $viewer->createToken('test')->plainTextToken;

        $guest = $this->getJson('/api/public/contact-fields?_metadata=1')->assertOk();
        $guest->assertJsonPath('phone', '+233500000001')
            ->assertJsonPath('whatsapp_phone', null)
            ->assertJsonPath('contact_email', null)
            ->assertJsonPath('website_url', 'https://example.com')
            ->assertJsonPath('visible_contact_fields.phone', true)
            ->assertJsonPath('visible_contact_fields.whatsapp', false)
            ->assertJsonPath('visible_contact_fields.email', false)
            ->assertJsonPath('visible_contact_fields.website', true);

        $this->withToken($token)
            ->getJson('/api/public/contact-fields?_metadata=1')
            ->assertOk()
            ->assertJsonPath('contact_email', null);

        Connection::create([
            'member_id' => $owner->id,
            'guest_user_id' => $viewer->id,
            'guest_name' => 'Viewer',
            'guest_email' => $viewer->email,
            'status' => 'approved',
        ]);

        $this->withToken($token)
            ->getJson('/api/public/contact-fields?_metadata=1')
            ->assertOk()
            ->assertJsonPath('contact_email', 'public-contact@example.com')
            ->assertJsonPath('user.email', 'public-contact@example.com')
            ->assertJsonPath('visible_contact_fields.email', true);
    }

    public function test_public_poster_profile_includes_published_phase2_content(): void
    {
        $user = $this->member();
        $profile = $this->publishedProfile($user, [
            'slug' => 'poster-content',
            'profile_type' => 'business',
            'display_name' => 'Roicard Studio',
            'title' => 'Brand and product design studio',
            'skills' => ['Brand strategy', 'Product design'],
            'services' => ['Design sprints', 'Brand identity'],
        ]);

        $response = $this->getJson('/api/public/poster-content?_metadata=1')->assertOk();
        $response->assertJsonPath('profile_type', 'business')
            ->assertJsonPath('display_name', 'Roicard Studio')
            ->assertJsonPath('is_published', true)
            ->assertJsonPath('skills.0', 'Brand strategy')
            ->assertJsonPath('services.0', 'Design sprints');
    }

    public function test_publish_is_owner_only(): void
    {
        $owner = $this->member();
        $intruder = $this->member();
        $profile = $owner->profiles()->create([
            'title' => 'Private profile',
            'visibility' => 'private',
            'privacy_confirmed_at' => now(),
        ]);
        $token = $intruder->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/publish")
            ->assertStatus(403);
    }

    public function test_business_profile_creation_and_default_switching(): void
    {
        $user = $this->member();
        $personal = $user->profiles()->create(['title' => 'Personal']);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/profiles', [
            'profile_type' => 'business',
            'display_name' => 'Acme Studio',
            'title' => 'Creative studio',
            'organisation' => 'Acme Studio Ltd',
            'bio' => 'We design brands.',
            'visibility' => 'public',
            'confirm_privacy' => true,
        ])->assertCreated();

        $businessId = $response->json('profile.id');
        $this->assertSame('business', $response->json('profile.profile_type'));

        $this->withToken($token)
            ->postJson("/api/profiles/{$businessId}/default")
            ->assertOk();

        $list = $this->withToken($token)->getJson('/api/profiles')->assertOk();
        $this->assertSame($businessId, $list->json('default_profile_id'));
        $this->assertTrue($personal->fresh()->is_default === false);
    }
}
