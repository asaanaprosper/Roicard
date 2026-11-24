<?php

namespace Tests\Feature;

use App\Models\AdminActionLog;
use App\Models\Category;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Mvp2AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_admin_category_creation_writes_actor_and_polymorphic_target(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/categories', [
            'kind' => Category::KIND_SEEKING,
            'name' => 'Hiring',
            'description' => 'Members looking to hire',
        ]);

        $response->assertCreated();
        $categoryId = $response->json('category.id');

        $log = AdminActionLog::where('action', 'category.created')->firstOrFail();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame('Category', $log->target_type);
        $this->assertSame((string) $categoryId, $log->target_id);
        $this->assertSame('Hiring', $log->metadata['name']);
        $this->assertNotNull($log->created_at);

        $this->withToken($token)
            ->getJson('/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'category.created')
            ->assertJsonPath('data.0.actor.id', $admin->id);
    }

    public function test_existing_admin_actions_still_use_the_reusable_audit_trail(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['status' => 'draft']);
        $token = $admin->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/admin/users/{$target->id}", ['status' => 'active'])
            ->assertOk();

        $log = AdminActionLog::where('action', 'activate_membership')
            ->where('target_user_id', $target->id)
            ->firstOrFail();

        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('User', $log->target_type);
        $this->assertSame((string) $target->id, $log->target_id);
    }

    public function test_plan_entitlement_changes_are_audited(): void
    {
        $admin = $this->admin();
        $plan = Plan::create(['key' => 'probe', 'name' => 'Probe']);
        $token = $admin->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/admin/plans/{$plan->id}/entitlements", [
                'entitlements' => [
                    'PROFILE_COUNT' => 4,
                    'AI_CALLS_PER_DAY' => null,
                ],
            ])
            ->assertOk();

        $log = AdminActionLog::where('action', 'plan.entitlements_updated')->firstOrFail();

        $this->assertSame('Plan', $log->target_type);
        $this->assertSame((string) $plan->id, $log->target_id);
        $this->assertSame(4, $log->metadata['after']['PROFILE_COUNT']);
    }

    public function test_security_sensitive_member_action_is_audited(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'password',
                'new_password' => 'New-password-123',
                'new_password_confirmation' => 'New-password-123',
            ])
            ->assertOk();

        $log = AdminActionLog::where('action', 'security.password_changed')->firstOrFail();

        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame('User', $log->target_type);
        $this->assertSame((string) $user->id, $log->target_id);
        $this->assertNotNull($log->created_at);
    }

    public function test_non_admins_cannot_read_audit_logs(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/audit-logs')
            ->assertStatus(403);
    }
}
