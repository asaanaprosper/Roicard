<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Phase7LegacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_tagging_command_preserves_paid_members_without_changing_plan(): void
    {
        Role::firstOrCreate(['name' => 'legacy_member', 'guard_name' => 'web']);

        $paid = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
            'legacy_member' => false,
        ]);
        Payment::create([
            'user_id' => $paid->id,
            'amount' => 350,
            'currency' => 'GHS',
            'status' => Payment::STATUS_SUCCESS,
            'purpose' => Payment::PURPOSE_MEMBERSHIP,
            'provider_reference' => 'LEGACY-' . $paid->id,
            'provider' => 'mock',
        ]);

        $unpaid = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->artisan('legacy:tag-members')->assertSuccessful();

        $paid->refresh();
        $this->assertTrue($paid->legacy_member);
        $this->assertNotNull($paid->legacy_member_since);
        $this->assertTrue($paid->hasRole('legacy_member'));
        $this->assertNull($paid->plan_id);
        $this->assertFalse($unpaid->fresh()->legacy_member);
    }

    public function test_legacy_entitlements_use_configured_fallback_when_free_plan_is_restrictive(): void
    {
        config(['roicard.plans.limits.PROFILE_COUNT' => 8]);
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $free = \App\Models\Plan::where('key', 'free')->firstOrFail();
        \App\Models\PlanEntitlement::create([
            'plan_id' => $free->id,
            'limit_key' => \App\Support\PlanLimitKey::PROFILE_COUNT,
            'limit_value' => 1,
        ]);

        $legacy = User::factory()->create([
            'status' => 'active',
            'legacy_member' => true,
            'legacy_member_since' => now(),
        ]);

        $this->assertSame(8, app(\App\Services\EntitlementService::class)
            ->limit($legacy, \App\Support\PlanLimitKey::PROFILE_COUNT));
    }
}
