<?php

namespace Tests\Feature;

use App\Models\OnboardingTask;
use App\Models\Profile;
use App\Models\User;
use App\Services\OnboardingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['status' => 'active'], $attributes));
    }

    public function test_registration_creates_setup_tasks_and_returns_dashboard_routing(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Mensah',
            'email' => 'ada.phase2@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'privacy_consent' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.next_step', 'dashboard');

        $user = User::where('email', 'ada.phase2@example.com')->firstOrFail();

        $this->assertSame(8, $user->onboardingTasks()->count());
        $this->assertDatabaseHas('onboarding_tasks', [
            'user_id' => $user->id,
            'task_key' => 'build_profile',
            'state' => OnboardingTask::STATE_TODO,
        ]);
    }

    public function test_progress_is_server_backed_and_tasks_persist_across_requests(): void
    {
        $user = $this->member();
        $profile = $user->profiles()->create([
            'title' => 'Product Designer',
            'visibility' => 'public',
            'privacy_confirmed_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $status = $this->withToken($token)->getJson('/api/onboarding')->assertOk();
        $status->assertJsonPath('required_complete', false)
            ->assertJsonPath('progress.required_total', 3)
            ->assertJsonPath('progress.required_done', 2)
            ->assertJsonPath('next_task.key', 'publish');

        $this->withToken($token)
            ->patchJson('/api/onboarding/tasks/seeking', ['state' => 'done'])
            ->assertOk();

        // No seeking data was saved, so the derived state correctly returns to todo.
        $this->withToken($token)
            ->getJson('/api/onboarding')
            ->assertOk()
            ->assertJsonPath('tasks.4.state', 'todo');

        $profile->update(['seeking' => 'Looking for design partners']);
        $this->withToken($token)
            ->patchJson('/api/onboarding/tasks/seeking', ['state' => 'done'])
            ->assertOk()
            ->assertJsonPath('tasks.4.state', 'done');

        // A fresh request reads the persisted state, not frontend-only state.
        $this->withToken($token)
            ->getJson('/api/onboarding')
            ->assertOk()
            ->assertJsonPath('tasks.4.state', 'done');
    }

    public function test_optional_tasks_can_be_skipped_and_moved_back_from_later(): void
    {
        $user = $this->member();
        $user->profiles()->create(['title' => 'Engineer']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/onboarding/tasks/offering', ['state' => 'skipped'])
            ->assertOk()
            ->assertJsonFragment(['key' => 'offering', 'state' => 'skipped']);

        $this->withToken($token)
            ->getJson('/api/onboarding')
            ->assertOk()
            ->assertJsonFragment(['key' => 'offering', 'state' => 'skipped']);

        $this->withToken($token)
            ->patchJson('/api/onboarding/tasks/offering', ['state' => 'todo'])
            ->assertOk()
            ->assertJsonPath('tasks.5.state', 'todo');
    }

    public function test_goals_are_saved_and_prefill_seeking_and_offering(): void
    {
        $user = $this->member();
        $profile = $user->profiles()->create(['title' => 'Engineer']);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/onboarding/goals', [
            'goals' => ['find_opportunities', 'showcase_work'],
        ])->assertOk();

        $response->assertJsonPath('goals.0', 'find_opportunities')
            ->assertJsonPath('goals.1', 'showcase_work')
            ->assertJsonPath('goals_dismissed', true);

        $profile->refresh();
        $this->assertNotEmpty($profile->seeking);
        $this->assertNotEmpty($profile->offering);
        $this->assertSame(['find_opportunities', 'showcase_work'], $user->fresh()->onboarding_goals);
    }

    public function test_duplicate_onboarding_task_rows_are_prevented(): void
    {
        $user = $this->member();
        $service = app(OnboardingService::class);

        $service->ensureTasks($user);
        $service->ensureTasks($user);
        $this->assertSame(8, $user->onboardingTasks()->count());

        $this->expectException(QueryException::class);
        OnboardingTask::create([
            'user_id' => $user->id,
            'task_key' => 'build_profile',
            'state' => OnboardingTask::STATE_TODO,
        ]);
    }

    public function test_setup_card_can_be_dismissed(): void
    {
        $user = $this->member();
        $user->profiles()->create(['title' => 'Engineer']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/onboarding/setup-card/dismiss')
            ->assertOk()
            ->assertJsonPath('setup_card_dismissed', true)
            ->assertJsonPath('setup_card_visible', false);
    }
}
