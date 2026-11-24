<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\CatalogEnquiryReceivedNotification;
use App\Services\MatchmakingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase4CatalogTest extends TestCase
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
            'slug' => 'catalog-' . $user->id . '-' . uniqid(),
            'profile_type' => 'business',
            'display_name' => 'Business ' . $user->id,
            'title' => 'Business services',
            'organisation' => 'Acme',
            'location' => 'Accra, Ghana',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
            'is_contactable' => true,
            'completion_pct' => 80,
        ], $attributes));
    }

    private function category(string $name = 'Consulting'): Category
    {
        return Category::create([
            'kind' => Category::KIND_OFFERING,
            'name' => $name,
            'slug' => strtolower($name) . '-' . uniqid(),
            'is_active' => true,
        ]);
    }

    private function itemPayload(Category $category, array $extra = []): array
    {
        return array_merge([
            'type' => 'service',
            'title' => 'Growth strategy session',
            'description' => 'A practical growth strategy session for founders.',
            'category_id' => $category->id,
            'price' => 1500,
            'price_mode' => 'fixed',
            'currency' => 'GHS',
            'cta_type' => 'enquire',
            'is_active' => true,
        ], $extra);
    }

    public function test_catalog_crud_syncs_a_single_offering_signal(): void
    {
        $user = $this->member();
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;
        $category = $this->category();

        $created = $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/catalog", $this->itemPayload($category))
            ->assertCreated()
            ->assertJsonPath('item.title', 'Growth strategy session')
            ->assertJsonPath('item.price_mode', 'fixed')
            ->assertJsonPath('item.price_label', 'GHS 1,500.00');

        $itemId = $created->json('item.id');

        $this->assertDatabaseHas('business_catalog_items', ['id' => $itemId]);
        $this->assertDatabaseHas('profile_signals', [
            'profile_id' => $profile->id,
            'catalog_item_id' => $itemId,
            'type' => 'offering',
            'source' => 'catalog',
            'is_active' => true,
        ]);
        $this->assertSame(1, $profile->signals()->where('catalog_item_id', $itemId)->count());

        $this->using($token)
            ->patchJson("/api/profiles/{$profile->id}/catalog/{$itemId}", [
                'title' => 'Growth strategy workshop',
                'description' => 'Same session, new title.',
            ])
            ->assertOk()
            ->assertJsonPath('item.title', 'Growth strategy workshop');

        $this->assertSame(1, $profile->signals()->where('catalog_item_id', $itemId)->count());

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/catalog/{$itemId}/deactivate")
            ->assertOk()
            ->assertJsonPath('item.is_active', false);

        $this->assertDatabaseHas('profile_signals', [
            'catalog_item_id' => $itemId,
            'is_active' => false,
        ]);

        $this->using($token)
            ->deleteJson("/api/profiles/{$profile->id}/catalog/{$itemId}")
            ->assertOk();

        $this->assertDatabaseMissing('business_catalog_items', ['id' => $itemId]);
        $this->assertDatabaseMissing('profile_signals', ['catalog_item_id' => $itemId]);
    }

    public function test_catalog_entitlement_counts_only_active_items(): void
    {
        config(['roicard.plans.limits.CATALOG_ITEMS' => 1]);

        $user = $this->member();
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;
        $category = $this->category();

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/catalog", $this->itemPayload($category))
            ->assertCreated();

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/catalog", $this->itemPayload($category, ['title' => 'Second active']))
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'CATALOG_ITEMS');

        $paused = $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/catalog", $this->itemPayload($category, [
                'title' => 'Paused extra',
                'is_active' => false,
            ]))
            ->assertCreated();

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/catalog/{$paused->json('item.id')}/activate")
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'CATALOG_ITEMS');
    }

    public function test_catalog_image_validation_maximum_and_removal(): void
    {
        config(['roicard.catalog.max_images_per_item' => 2]);

        $user = $this->member();
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;
        $category = $this->category();
        $item = CatalogItem::create([
            'profile_id' => $profile->id,
            'type' => 'service',
            'title' => 'Image test',
            'category_id' => $category->id,
            'price_mode' => 'on_request',
            'currency' => 'GHS',
            'cta_type' => 'enquire',
            'is_active' => true,
        ]);

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/catalog/{$item->id}/images", [
                'images' => [UploadedFile::fake()->image('one.jpg')],
            ])
            ->assertOk();

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/catalog/{$item->id}/images", [
                'images' => [
                    UploadedFile::fake()->image('two.jpg'),
                    UploadedFile::fake()->image('three.jpg'),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/catalog/{$item->id}/images", [
                'images' => [UploadedFile::fake()->create('not-an-image.pdf', 10, 'application/pdf')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);

        $media = $item->fresh()->getMedia('images')->first();
        $this->using($token)
            ->deleteJson("/api/profiles/{$profile->id}/catalog/{$item->id}/images/{$media->id}")
            ->assertOk();

        $this->assertSame(0, $item->fresh()->getMedia('images')->count());
    }

    public function test_catalog_ownership_is_enforced(): void
    {
        $owner = $this->member();
        $intruder = $this->member();
        $profile = $this->profile($owner);
        $category = $this->category();
        $item = CatalogItem::create([
            'profile_id' => $profile->id,
            'type' => 'service',
            'title' => 'Owned item',
            'category_id' => $category->id,
            'price_mode' => 'on_request',
            'currency' => 'GHS',
            'cta_type' => 'enquire',
            'is_active' => true,
        ]);

        $this->using($intruder->createToken('test')->plainTextToken)
            ->postJson("/api/profiles/{$profile->id}/catalog", $this->itemPayload($category))
            ->assertStatus(403);

        $this->using($intruder->createToken('test')->plainTextToken)
            ->patchJson("/api/profiles/{$profile->id}/catalog/{$item->id}", ['title' => 'Hijacked'])
            ->assertStatus(403);
    }

    public function test_business_branding_and_public_catalog_payload(): void
    {
        $user = $this->member();
        $profile = $this->profile($user);
        $token = $user->createToken('test')->plainTextToken;
        $category = $this->category();

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/logo", [
                'image' => UploadedFile::fake()->image('logo.png', 200, 200),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Business logo updated.');

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/cover", [
                'image' => UploadedFile::fake()->image('cover.jpg', 800, 400),
            ])
            ->assertOk();

        $item = $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/catalog", $this->itemPayload($category, [
                'title' => 'Public catalog item',
            ]))
            ->assertCreated();

        $public = $this->getJson('/api/public/' . $profile->slug)->assertOk();
        $this->assertNotNull($public->json('logo_url'));
        $this->assertNotNull($public->json('cover_image_url'));
        $this->assertSame('Public catalog item', $public->json('catalog_items.0.title'));

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/catalog/{$item->json('item.id')}/deactivate")
            ->assertOk();

        $publicAfter = $this->getJson('/api/public/' . $profile->slug)->assertOk();
        $this->assertCount(0, $publicAfter->json('catalog_items'));
    }

    public function test_branding_is_rejected_on_personal_profiles(): void
    {
        $user = $this->member();
        $profile = $this->profile($user, ['profile_type' => 'personal']);
        $token = $user->createToken('test')->plainTextToken;

        $this->using($token)
            ->postJson("/api/profiles/{$profile->id}/logo", [
                'image' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(422);
    }

    public function test_guest_can_enquire_on_a_public_catalog_item_and_owner_is_notified(): void
    {
        Notification::fake();

        $owner = $this->member();
        $profile = $this->profile($owner);
        $category = $this->category();
        $item = CatalogItem::create([
            'profile_id' => $profile->id,
            'type' => 'service',
            'title' => 'Brand workshop',
            'description' => 'A workshop.',
            'category_id' => $category->id,
            'price' => 900,
            'price_mode' => 'fixed',
            'currency' => 'GHS',
            'cta_type' => 'enquire',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/catalog/{$item->id}/enquiries", [
            'name' => 'Guest Buyer',
            'email' => 'guest@example.com',
            'phone' => '+233500000000',
            'message' => 'Is this available next week?',
        ])->assertCreated();

        $this->assertDatabaseHas('catalog_enquiries', [
            'id' => $response->json('enquiry.id'),
            'catalog_item_id' => $item->id,
            'business_profile_id' => $profile->id,
            'name' => 'Guest Buyer',
            'source' => 'public',
        ]);

        Notification::assertSentTo($owner, CatalogEnquiryReceivedNotification::class);

        $this->postJson("/api/catalog/{$item->id}/enquiries", [
            'name' => 'Missing email',
            'message' => 'No email',
        ])->assertStatus(422);
    }

    public function test_member_enquiry_is_associated_and_private_catalogs_are_hidden(): void
    {
        $owner = $this->member();
        $viewer = $this->member();
        $privateProfile = $this->profile($owner, [
            'visibility' => 'private',
            'is_searchable' => false,
        ]);
        $category = $this->category();
        $item = CatalogItem::create([
            'profile_id' => $privateProfile->id,
            'type' => 'product',
            'title' => 'Private product',
            'category_id' => $category->id,
            'price_mode' => 'on_request',
            'currency' => 'GHS',
            'cta_type' => 'enquire',
            'is_active' => true,
        ]);

        $this->using($viewer->createToken('test')->plainTextToken)
            ->postJson("/api/catalog/{$item->id}/enquiries", [
                'name' => 'Member',
                'email' => $viewer->email,
                'message' => 'Interested',
            ])
            ->assertStatus(422);

        $privateProfile->update(['visibility' => 'public', 'is_live' => true, 'is_published' => true]);

        $this->using($viewer->createToken('test')->plainTextToken)
            ->postJson("/api/catalog/{$item->id}/enquiries", [
                'name' => 'Member',
                'email' => $viewer->email,
                'message' => 'Interested',
            ])
            ->assertCreated()
            ->assertJsonPath('enquiry.catalog_item_id', $item->id);

        $this->assertDatabaseHas('catalog_enquiries', [
            'catalog_item_id' => $item->id,
            'sender_user_id' => $viewer->id,
            'source' => 'member',
        ]);
    }

    public function test_active_catalog_items_appear_in_search_and_drive_matchmaking(): void
    {
        $viewer = $this->member();
        $viewerProfile = $this->profile($viewer, ['profile_type' => 'personal', 'organisation' => 'Viewer Co']);
        $seekingCategory = Category::create([
            'kind' => Category::KIND_SEEKING,
            'name' => 'Brand strategy',
            'slug' => 'brand-strategy',
            'is_active' => true,
        ]);
        $signal = $viewerProfile->signals()->create([
            'type' => 'seeking',
            'description' => 'Seeking brand strategy support.',
            'is_active' => true,
        ]);
        $signal->categories()->sync([$seekingCategory->id]);

        $business = $this->member();
        $businessProfile = $this->profile($business, ['organisation' => 'Studio Z']);
        $offeringCategory = $this->category('Brand strategy');
        $token = $business->createToken('test')->plainTextToken;

        $created = $this->using($token)
            ->postJson("/api/profiles/{$businessProfile->id}/catalog", $this->itemPayload($offeringCategory, [
                'title' => 'Brand sprint',
                'description' => 'A focused brand sprint.',
            ]))
            ->assertCreated();

        $viewerToken = $viewer->createToken('test')->plainTextToken;

        $search = $this->using($viewerToken)
            ->getJson('/api/search?q=' . urlencode('Brand sprint'))
            ->assertOk();
        $this->assertTrue(collect($search->json('data'))->pluck('profile.id')->contains($businessProfile->id));

        $recommendations = app(MatchmakingService::class)->recommendations($viewer, $viewerProfile);
        $match = collect($recommendations['items'])->firstWhere('profile.id', $businessProfile->id);
        $this->assertNotNull($match);

        $this->using($token)
            ->postJson("/api/profiles/{$businessProfile->id}/catalog/{$created->json('item.id')}/deactivate")
            ->assertOk();

        $searchAfter = $this->using($viewerToken)
            ->getJson('/api/search?q=' . urlencode('Brand sprint'))
            ->assertOk();
        $this->assertFalse(collect($searchAfter->json('data'))->pluck('profile.id')->contains($businessProfile->id));

        $recommendationsAfter = app(MatchmakingService::class)->recommendations($viewer, $viewerProfile);
        $this->assertNull(collect($recommendationsAfter['items'])->firstWhere('profile.id', $businessProfile->id));
    }
}
