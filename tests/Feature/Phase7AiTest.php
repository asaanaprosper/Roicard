<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Category;
use App\Models\EntitlementUsage;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiUsageService;
use App\Services\Ai\DocumentTextExtractor;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\SensitiveDataRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class Phase7AiTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['status' => 'active'], $attributes));
    }

    private function consented(User $user): User
    {
        $user->forceFill([
            'ai_consent_at' => now(),
            'ai_consent_version' => (string) config('roicard.ai.consent_version'),
        ])->save();

        return $user->fresh();
    }

    private function profile(User $user, array $attributes = []): Profile
    {
        return $user->profiles()->create(array_merge([
            'display_name' => 'AI Test Member',
            'title' => 'Existing headline',
            'bio' => 'Existing member text',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
        ], $attributes));
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function fakeGemini(array $data, array $usage = [], ?callable $onPrompt = null): void
    {
        $usage = array_merge([
            'input_tokens' => 120,
            'output_tokens' => 80,
            'total_tokens' => 200,
        ], $usage);

        $mock = $this->mock(GeminiClient::class);
        $mock->shouldReceive('configured')->andReturnTrue();
        $mock->shouldReceive('model')->andReturn('gemini-test');
        $mock->shouldReceive('region')->andReturn('global');
        $mock->shouldReceive('generateJson')->andReturnUsing(
            function (string $system, string $userPrompt, array $schema) use ($data, $usage, $onPrompt) {
                if ($onPrompt) {
                    $onPrompt($userPrompt);
                }

                return [
                    'data' => $data,
                    'usage' => $usage,
                    'model' => 'gemini-test',
                ];
            },
        );
    }

    private function aiUsed(User $user): int
    {
        return (int) EntitlementUsage::query()
            ->where('user_id', $user->id)
            ->where('limit_key', 'AI_CALLS_PER_DAY')
            ->value('used');
    }

    public function test_ai_consent_is_required_before_any_provider_call_or_quota_use(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $this->fakeGemini(['headline' => 'Drafted headline', 'about' => 'Drafted about']);

        $user = $this->member();
        $profile = $this->profile($user);
        $token = $this->token($user);

        $this->withToken($token)
            ->getJson('/api/ai/status')
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('consent_required', true)
            ->assertJsonStructure(['consent_text', 'daily_limit', 'used_today', 'remaining_today']);

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/ai-draft/request", [
                'source_text' => 'I design mobile products.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent_required']);

        $this->assertSame(0, $this->aiUsed($user));
        $this->assertDatabaseCount('ai_usage_logs', 0);

        $this->withToken($token)
            ->postJson('/api/ai/consent', ['accepted' => true])
            ->assertOk()
            ->assertJsonPath('consent_required', false)
            ->assertJsonPath('available', true);

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/ai-draft/request", [
                'source_text' => 'I design mobile products.',
            ])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('draft.headline', 'Drafted headline');

        $this->assertSame(1, $this->aiUsed($user));
    }

    public function test_profile_builder_stores_proposed_fields_and_requires_individual_approval(): void
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'roicard.ai.max_catalog_items' => 5,
        ]);

        $capturedPrompt = null;

        $this->fakeGemini([
            'headline' => 'AI Headline',
            'about' => 'AI About text',
            'experience' => [[
                'title' => 'Product Lead',
                'company' => 'Acme',
                'start_date' => '2020-01-01',
                'end_date' => '2022-01-01',
                'location' => 'Accra',
            ]],
            'skills' => ['Product design', 'Research'],
            'services' => ['Product strategy'],
            'seeking' => 'Co-founders',
            'offering' => 'Product advice',
        ], [], function (string $prompt) use (&$capturedPrompt) {
            $capturedPrompt = $prompt;
        });

        $user = $this->consented($this->member());
        $profile = $this->profile($user);
        $token = $this->token($user);

        $response = $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/ai-draft/request", [
                'source_text' => 'Product Lead at Acme. Contact me at person@example.com or +233 55 123 4567.',
            ])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('draft.headline', 'AI Headline')
            ->assertJsonPath('draft.about', 'AI About text');

        $profile->refresh();

        // Proposed only: live fields and publication state are untouched.
        $this->assertSame('Existing headline', $profile->title);
        $this->assertSame('Existing member text', $profile->bio);
        $this->assertFalse($profile->is_published);
        $this->assertSame('public', $profile->visibility);
        $this->assertSame('ai', $profile->ai_draft['source']);
        $this->assertSame('proposed', $profile->ai_draft['status']);
        $this->assertSame([], $profile->ai_approved_fields);

        // Contact details are stripped before the provider call.
        $this->assertNotNull($capturedPrompt);
        $this->assertStringNotContainsString('person@example.com', $capturedPrompt);
        $this->assertStringNotContainsString('+233 55 123 4567', $capturedPrompt);
        $this->assertStringContainsString('contact detail removed', $capturedPrompt);

        // Publishing is blocked until every generated field is approved.
        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ai_draft']);

        $this->withToken($token)
            ->patchJson("/api/profiles/{$profile->id}/ai-draft/approve", ['fields' => ['headline']])
            ->assertOk();

        $this->assertSame('AI Headline', $profile->fresh()->title);
        $this->assertSame('Existing member text', $profile->fresh()->bio);

        $this->withToken($token)
            ->patchJson("/api/profiles/{$profile->id}/ai-draft/approve", [
                'fields' => ['about', 'experience', 'skills', 'services', 'seeking', 'offering'],
            ])
            ->assertOk();

        $profile->refresh();
        $this->assertSame('AI About text', $profile->bio);
        $this->assertSame(['Product design', 'Research'], $profile->skills);
        $this->assertSame(['Product strategy'], $profile->services);
        $this->assertSame('Co-founders', $profile->seeking);
        $this->assertSame('Product advice', $profile->offering);
        $this->assertSame(1, $profile->experience()->count());
        $this->assertSame(
            ['headline', 'about', 'experience', 'skills', 'services', 'seeking', 'offering'],
            $profile->ai_approved_fields,
        );

        // Approval never changes visibility or publishes by itself.
        $this->assertFalse($profile->is_published);
        $this->assertSame('public', $profile->visibility);

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/publish")
            ->assertOk()
            ->assertJsonPath('profile.is_published', true);

        $log = AiUsageLog::firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('profile_builder', $log->feature);
        $this->assertSame('success', $log->outcome);
        $this->assertSame(120, $log->input_tokens);
        $this->assertSame(80, $log->output_tokens);
        $this->assertStringNotContainsString('person@example.com', json_encode($log->toArray()) ?: '');
        $this->assertStringNotContainsString('Product Lead at Acme', json_encode($log->toArray()) ?: '');
    }

    public function test_daily_ai_limit_is_enforced_once_per_attempted_call(): void
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'roicard.plans.limits.AI_CALLS_PER_DAY' => 1,
        ]);

        $mock = $this->mock(GeminiClient::class);
        $mock->shouldReceive('configured')->andReturnTrue();
        $mock->shouldReceive('model')->andReturn('gemini-test');
        $mock->shouldReceive('region')->andReturn('global');
        $mock->shouldReceive('generateJson')->once()->andReturn([
            'data' => ['headline' => 'One call', 'about' => 'One call about'],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
            'model' => 'gemini-test',
        ]);

        $user = $this->consented($this->member());
        $profile = $this->profile($user);
        $token = $this->token($user);

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/ai-draft/request", ['source_text' => 'First source'])
            ->assertOk()
            ->assertJsonPath('available', true);

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/ai-draft/request", ['source_text' => 'Second source'])
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'AI_CALLS_PER_DAY');

        $this->assertSame(1, $this->aiUsed($user));
        $this->assertDatabaseCount('ai_usage_logs', 1);
    }

    public function test_missing_gemini_credentials_return_manual_fallback_without_consuming_quota(): void
    {
        config(['services.gemini.api_key' => null]);

        $user = $this->consented($this->member());
        $profile = $this->profile($user);
        $token = $this->token($user);

        $this->withToken($token)
            ->getJson('/api/ai/status')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('available', false);

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/ai-draft/request", [
                'source_text' => 'Some professional information.',
            ])
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('draft', null);

        $this->assertSame(0, $this->aiUsed($user));
        $this->assertDatabaseCount('ai_usage_logs', 0);
    }

    public function test_catalog_draft_requires_business_profile_and_item_level_approval(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $category = Category::create([
            'kind' => Category::KIND_OFFERING,
            'name' => 'Consulting',
            'slug' => 'consulting',
        ]);

        $this->fakeGemini([
            'about' => 'We help teams ship better products.',
            'items' => [[
                'type' => 'service',
                'title' => 'Product discovery sprint',
                'description' => 'A one-week discovery engagement.',
                'category' => 'Consulting',
            ]],
        ]);

        $user = $this->consented($this->member());
        $profile = $this->profile($user, [
            'profile_type' => 'business',
            'display_name' => 'Acme Studio',
        ]);
        $token = $this->token($user);

        $response = $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/ai-draft/catalog", [
                'source_text' => 'We run product discovery sprints for growing teams.',
            ])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('business_about', 'We help teams ship better products.');

        $itemId = $response->json('catalog_draft.items.0.id');
        $this->assertNotEmpty($itemId);
        $this->assertSame($category->id, $response->json('catalog_draft.items.0.category_id'));

        // Proposal only: no live catalog item and no live About text yet.
        $this->assertSame(0, $profile->catalogItems()->count());
        $this->assertSame('Existing member text', $profile->fresh()->bio);

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/ai-draft/catalog/approve", [
                'item_ids' => [$itemId],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.title', 'Product discovery sprint');

        $this->assertSame(1, $profile->catalogItems()->count());
        $this->assertSame('Product discovery sprint', $profile->catalogItems()->first()->title);

        $this->withToken($token)
            ->patchJson("/api/profiles/{$profile->id}/ai-draft/approve", ['fields' => ['about']])
            ->assertOk();

        $this->assertSame('We help teams ship better products.', $profile->fresh()->bio);
    }

    public function test_catalog_approval_cannot_bypass_the_catalog_items_entitlement(): void
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'roicard.plans.limits.CATALOG_ITEMS' => 1,
        ]);

        $this->fakeGemini([
            'about' => 'We do many things.',
            'items' => [
                ['type' => 'service', 'title' => 'One', 'description' => '', 'category' => ''],
                ['type' => 'service', 'title' => 'Two', 'description' => '', 'category' => ''],
            ],
        ]);

        $user = $this->consented($this->member());
        $profile = $this->profile($user, ['profile_type' => 'business']);
        $token = $this->token($user);

        $response = $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/ai-draft/catalog", [
                'source_text' => 'We provide services.',
            ])
            ->assertOk();

        $itemIds = collect($response->json('catalog_draft.items'))->pluck('id')->all();
        $this->assertCount(2, $itemIds);

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/ai-draft/catalog/approve", [
                'item_ids' => $itemIds,
            ])
            ->assertStatus(422)
            ->assertJsonPath('limit_key', 'CATALOG_ITEMS');

        $this->assertSame(0, $profile->catalogItems()->count());
    }

    public function test_natural_language_search_is_interpreted_without_executing_or_consuming_search_quota(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        Category::create([
            'kind' => Category::KIND_OFFERING,
            'name' => 'Mobile App Development',
            'slug' => 'mobile-app-development',
        ]);

        $this->fakeGemini([
            'q' => 'mobile app developer',
            'result_type' => 'business',
            'category' => 'Mobile App Development',
            'location' => 'Accra',
            'organization' => '',
            'community' => '',
            'verified' => false,
            'summary' => 'Looking for a business that builds mobile apps in Accra.',
            'confidence' => 0.82,
        ]);

        $user = $this->consented($this->member());
        $token = $this->token($user);

        $this->withToken($token)
            ->postJson('/api/search/interpret', ['q' => 'I need someone to build a mobile app in Accra'])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('interpretation.filters.category', 'mobile-app-development')
            ->assertJsonPath('interpretation.filters.result_type', 'business')
            ->assertJsonPath('interpretation.filters.location', 'Accra')
            ->assertJsonMissingPath('data');

        $this->assertSame(1, $this->aiUsed($user));

        $searchUsed = (int) EntitlementUsage::query()
            ->where('user_id', $user->id)
            ->where('limit_key', 'SEARCHES_PER_DAY')
            ->value('used');

        $this->assertSame(0, $searchUsed);
    }

    public function test_cross_profile_reuse_requires_owned_source_and_only_proposes_fields(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $capturedPrompt = null;

        $this->fakeGemini([
            'headline' => 'Business strategy lead',
            'about' => 'I help businesses turn strategy into delivery.',
            'skills' => ['Strategy'],
        ], [], function (string $prompt) use (&$capturedPrompt) {
            $capturedPrompt = $prompt;
        });

        $user = $this->consented($this->member());
        $target = $this->profile($user, ['slug' => 'target-profile']);
        $source = $this->profile($user, [
            'slug' => 'source-profile',
            'title' => 'Strategy Director',
            'bio' => 'Strategy and delivery. Email source@example.com or call 024 123 4567.',
        ]);
        $other = $this->member();
        $otherProfile = $this->profile($other, ['slug' => 'someone-else']);

        $token = $this->token($user);

        $this->withToken($token)
            ->postJson("/api/profiles/{$target->id}/ai-draft/reuse", [
                'source_profile_id' => $otherProfile->id,
            ])
            ->assertForbidden();

        $this->withToken($token)
            ->postJson("/api/profiles/{$target->id}/ai-draft/reuse", [
                'source_profile_id' => $source->id,
            ])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('draft.headline', 'Business strategy lead');

        $this->assertSame('Existing headline', $target->fresh()->title);
        $this->assertNotNull($capturedPrompt);
        $this->assertStringNotContainsString('source@example.com', $capturedPrompt);
        $this->assertStringNotContainsString('024 123 4567', $capturedPrompt);
    }

    public function test_provider_timeout_degrades_to_manual_fallback_and_records_the_attempt(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $mock = $this->mock(GeminiClient::class);
        $mock->shouldReceive('configured')->andReturnTrue();
        $mock->shouldReceive('model')->andReturn('gemini-test');
        $mock->shouldReceive('region')->andReturn('global');
        $mock->shouldReceive('generateJson')->once()->andThrow(
            new AiProviderException('timeout', 'Timed out.'),
        );

        $user = $this->consented($this->member());
        $profile = $this->profile($user);
        $token = $this->token($user);

        $this->withToken($token)
            ->postJson("/api/profiles/{$profile->id}/ai-draft/request", [
                'source_text' => 'Some source text for the timeout fallback.',
            ])
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('draft', null);

        $log = AiUsageLog::firstOrFail();
        $this->assertSame('timeout', $log->outcome);
        $this->assertSame('timeout', $log->error_code);
        $this->assertSame(1, $this->aiUsed($user));
        $this->assertSame('Existing headline', $profile->fresh()->title);
    }

    public function test_sensitive_redactor_removes_contacts_but_keeps_dates(): void
    {
        $redacted = app(SensitiveDataRedactor::class)->redact(
            'Email person@example.com or call +233 55 123 4567. Worked 2019 - 2021 and 2020-2022.'
        );

        $this->assertStringNotContainsString('person@example.com', $redacted);
        $this->assertStringNotContainsString('+233 55 123 4567', $redacted);
        $this->assertStringContainsString('2019 - 2021', $redacted);
        $this->assertStringContainsString('2020-2022', $redacted);
    }

    public function test_docx_cv_text_is_extracted_locally(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'roicard-cv-');

        $this->assertIsString($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        $zip->addFromString(
            'word/document.xml',
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:r><w:t>Senior product designer with eight years of experience.</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Skills: research, prototyping and design systems.</w:t></w:r></w:p>'
            . '</w:body></w:document>'
        );
        $zip->close();

        try {
            $text = app(DocumentTextExtractor::class)->extractFromPath($path, 'cv.docx');

            $this->assertStringContainsString('Senior product designer', $text);
            $this->assertStringContainsString('design systems', $text);
        } finally {
            @unlink($path);
        }
    }

    public function test_monthly_ai_cost_alert_fires_once_when_pricing_is_configured(): void
    {
        config([
            'roicard.ai.input_price_per_million_usd' => 1,
            'roicard.ai.output_price_per_million_usd' => 1,
            'roicard.ai.monthly_cost_alert_usd' => 0.001,
        ]);

        Log::spy();

        $user = $this->member();
        $service = app(AiUsageService::class);

        $service->record($user, 'profile_builder', 'gemini-test', [
            'input_tokens' => 1000,
            'output_tokens' => 0,
            'total_tokens' => 1000,
        ], 'success');

        $service->record($user, 'profile_builder', 'gemini-test', [
            'input_tokens' => 1000,
            'output_tokens' => 0,
            'total_tokens' => 1000,
        ], 'success');

        Log::shouldHaveReceived('warning')
            ->with('ai.monthly_cost_alert', Mockery::on(function (array $context) {
                return $context['estimated_cost_usd'] >= 0.001
                    && $context['threshold_usd'] === 0.001;
            }))
            ->once();

        $this->assertSame(2, AiUsageLog::count());
        $this->assertGreaterThanOrEqual(0.002, $service->monthlyEstimatedCost());
    }
}
