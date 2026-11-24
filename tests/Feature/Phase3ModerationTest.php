<?php

namespace Tests\Feature;

use App\Models\AdminActionLog;
use App\Models\MemberReport;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase3ModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function using(string $token): static
    {
        // Sanctum caches the resolved guard user for the app instance; reset it
        // when a test switches between member tokens.
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

    private function admin(): User
    {
        return $this->member(['role' => 'admin']);
    }

    private function profile(User $user): Profile
    {
        return $user->profiles()->create([
            'slug' => 'moderation-' . $user->id . '-' . uniqid(),
            'title' => 'Member',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
            'is_live' => true,
            'is_published' => true,
            'is_searchable' => true,
        ]);
    }

    public function test_admin_can_review_moderation_queue_and_suspend_reported_member(): void
    {
        $reporter = $this->member();
        $reported = $this->member();
        $reportedProfile = $this->profile($reported);
        $admin = $this->admin();
        $adminToken = $admin->createToken('test')->plainTextToken;

        $report = MemberReport::create([
            'reporter_id' => $reporter->id,
            'reported_user_id' => $reported->id,
            'profile_id' => $reportedProfile->id,
            'reason_category' => 'harassment',
            'description' => 'Abusive messages.',
            'status' => MemberReport::STATUS_PENDING,
        ]);

        $this->using($adminToken)
            ->getJson('/api/admin/reports?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $report->id);

        $this->using($adminToken)
            ->patchJson("/api/admin/reports/{$report->id}", [
                'status' => 'actioned',
                'action' => 'suspend_user',
                'admin_notes' => 'Confirmed and suspended.',
            ])
            ->assertOk()
            ->assertJsonPath('report.status', MemberReport::STATUS_ACTIONED);

        $this->assertNotNull($reported->fresh()->deactivated_at);
        $this->assertTrue($reported->fresh()->isSuspendedByAdmin());

        $log = AdminActionLog::where('action', 'moderation.report_updated')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('MemberReport', $log->target_type);
        $this->assertSame((string) $report->id, $log->target_id);
    }

    public function test_non_admin_cannot_access_moderation_queue(): void
    {
        $user = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $this->using($token)
            ->getJson('/api/admin/reports')
            ->assertStatus(403);
    }

    public function test_member_cannot_read_private_moderation_details(): void
    {
        $user = $this->member();
        $reported = $this->member();
        $token = $user->createToken('test')->plainTextToken;

        $report = MemberReport::create([
            'reporter_id' => $user->id,
            'reported_user_id' => $reported->id,
            'reason_category' => 'spam',
            'status' => MemberReport::STATUS_PENDING,
        ]);

        $this->using($token)
            ->getJson("/api/admin/reports/{$report->id}")
            ->assertStatus(403);
    }
}
