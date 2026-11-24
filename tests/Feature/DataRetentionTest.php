<?php

namespace Tests\Feature;

use App\Models\AdminActionLog;
use App\Models\AiUsageLog;
use App\Models\RecommendationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DataRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'member', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_soft_deleted_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'retained@example.com',
            'status' => 'active',
            'password' => bcrypt('Pass123'),
        ]);
        $user->delete();

        $this->postJson('/api/auth/login', [
            'email' => 'retained@example.com',
            'password' => 'Pass123',
        ])->assertStatus(401);
    }

    public function test_soft_deleted_user_public_profile_is_hidden(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->profile()->create(['slug' => 'retained-user', 'is_live' => true]);
        $user->delete();

        $this->getJson('/api/public/retained-user')
            ->assertStatus(403)
            ->assertJson(['status' => 'draft']);
    }

    public function test_purge_removes_only_users_past_retention_window(): void
    {
        config(['app.data_retention_days' => 30]);

        $old = User::factory()->create(['status' => 'active']);
        $old->profile()->create(['slug' => 'old-user', 'is_live' => true]);
        $old->tokens()->create(['name' => 'session', 'token' => 'x', 'abilities' => ['*']]);
        $old->delete();
        User::withTrashed()->findOrFail($old->id)->forceFill(['deleted_at' => now()->subDays(60)])->save();

        $recent = User::factory()->create(['status' => 'active']);
        $recent->profile()->create(['slug' => 'recent-user', 'is_live' => true]);
        $recent->delete();

        $this->artisan('users:purge')->assertSuccessful();

        // Old account permanently gone, including its cascade-owned profile.
        $this->assertDatabaseMissing('users', ['id' => $old->id]);
        $this->assertDatabaseMissing('profiles', ['user_id' => $old->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $old->id]);

        // Recent account is still retained (soft-deleted).
        $this->assertSoftDeleted('users', ['id' => $recent->id]);
        $this->assertDatabaseHas('profiles', ['user_id' => $recent->id]);
    }

    public function test_retention_pruning_is_inactive_until_windows_are_configured(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $profile = $user->profile()->create(['slug' => 'retention-log-profile']);
        $other = $user->profiles()->create(['slug' => 'retention-log-profile-2']);

        $event = RecommendationEvent::create([
            'profile_id' => $profile->id,
            'recommended_profile_id' => $other->id,
            'event_type' => 'impression',
        ]);
        $audit = AdminActionLog::create([
            'actor_id' => $user->id,
            'admin_id' => $user->id,
            'action' => 'retention.test',
        ]);
        $ai = AiUsageLog::create([
            'user_id' => $user->id,
            'feature' => 'profile_builder',
            'provider' => 'gemini',
            'outcome' => 'success',
        ]);

        foreach ([$event, $audit, $ai] as $record) {
            $record->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();
        }

        $this->artisan('data:prune --dry-run')->assertSuccessful();

        $this->assertDatabaseHas('recommendation_events', ['id' => $event->id]);
        $this->assertDatabaseHas('admin_action_logs', ['id' => $audit->id]);
        $this->assertDatabaseHas('ai_usage_logs', ['id' => $ai->id]);
    }

    public function test_retention_pruning_removes_only_records_past_configured_windows(): void
    {
        config([
            'roicard.retention.recommendation_event_days' => 30,
            'roicard.retention.admin_action_log_days' => 30,
            'roicard.retention.ai_usage_log_days' => 30,
        ]);

        $user = User::factory()->create(['status' => 'active']);
        $profile = $user->profile()->create(['slug' => 'retention-prune-profile']);
        $other = $user->profiles()->create(['slug' => 'retention-prune-profile-2']);

        $oldEvent = RecommendationEvent::create([
            'profile_id' => $profile->id,
            'recommended_profile_id' => $other->id,
            'event_type' => 'impression',
        ]);
        $newEvent = RecommendationEvent::create([
            'profile_id' => $profile->id,
            'recommended_profile_id' => $other->id,
            'event_type' => 'impression',
        ]);
        $oldAudit = AdminActionLog::create([
            'actor_id' => $user->id,
            'admin_id' => $user->id,
            'action' => 'retention.old',
        ]);
        $oldAi = AiUsageLog::create([
            'user_id' => $user->id,
            'feature' => 'profile_builder',
            'provider' => 'gemini',
            'outcome' => 'success',
        ]);

        $oldEvent->forceFill(['created_at' => now()->subDays(60)])->saveQuietly();
        $oldAudit->forceFill(['created_at' => now()->subDays(60)])->saveQuietly();
        $oldAi->forceFill(['created_at' => now()->subDays(60)])->saveQuietly();

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertDatabaseMissing('recommendation_events', ['id' => $oldEvent->id]);
        $this->assertDatabaseHas('recommendation_events', ['id' => $newEvent->id]);
        $this->assertDatabaseMissing('admin_action_logs', ['id' => $oldAudit->id]);
        $this->assertDatabaseMissing('ai_usage_logs', ['id' => $oldAi->id]);
    }

    public function test_purge_honours_override_days(): void
    {
        config(['app.data_retention_days' => 30]);

        $older = User::factory()->create();
        $older->delete();
        User::withTrashed()->findOrFail($older->id)->forceFill(['deleted_at' => now()->subDays(15)])->save();

        // A shorter override removes it even though the default window is 30d.
        $this->artisan('users:purge', ['--days' => 7])->assertSuccessful();
        $this->assertDatabaseMissing('users', ['id' => $older->id]);

        $recent = User::factory()->create();
        $recent->delete();
        User::withTrashed()->findOrFail($recent->id)->forceFill(['deleted_at' => now()->subDays(3)])->save();

        $this->artisan('users:purge', ['--days' => 7])->assertSuccessful();
        $this->assertSoftDeleted('users', ['id' => $recent->id]);
    }
}
