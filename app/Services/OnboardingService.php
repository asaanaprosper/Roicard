<?php

namespace App\Services;

use App\Models\OnboardingTask;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Server-backed onboarding setup tasks.
 *
 * The setup dashboard is intentionally task-based rather than a compulsory
 * wizard. Task state is persisted per account so progress survives devices.
 */
class OnboardingService
{
    public const GROUP_GET_LIVE = 'get_live';
    public const GROUP_GET_MATCHED = 'get_matched';
    public const GROUP_GROW = 'grow';

    /**
     * Supported goal ids shown on the dismissible goals card.
     */
    public const GOALS = [
        'find_opportunities' => [
            'label' => 'Find new opportunities',
            'seeking' => 'I am open to new opportunities, roles and collaborations.',
        ],
        'showcase_work' => [
            'label' => 'Showcase my work',
            'offering' => 'I can share my work, experience and expertise with the community.',
        ],
        'grow_business' => [
            'label' => 'Grow my business',
            'offering' => 'I can offer products, services and business collaborations.',
        ],
        'build_network' => [
            'label' => 'Build my network',
            'offering' => 'I am open to meeting new people and building meaningful connections.',
        ],
        'hire_talent' => [
            'label' => 'Hire talent',
            'seeking' => 'I am looking to connect with talented people and trusted partners.',
        ],
        'learn' => [
            'label' => 'Learn and develop',
            'seeking' => 'I want to learn from experienced people and develop my skills.',
        ],
    ];

    /**
     * Setup task definitions. `required` tasks gate the completion of setup;
     * optional tasks remain in the setup card until dismissed.
     */
    public const TASKS = [
        'build_profile' => [
            'group' => self::GROUP_GET_LIVE,
            'title' => 'Build your profile',
            'description' => 'Add your headline and about section so people know what you do.',
            'required' => true,
            'action' => '/dashboard/setup/build-profile',
            'cta' => 'Build profile',
            'order' => 10,
        ],
        'add_photo' => [
            'group' => self::GROUP_GET_LIVE,
            'title' => 'Add a photo',
            'description' => 'A clear portrait makes your poster profile easier to recognise.',
            'required' => false,
            'action' => '/dashboard/setup/add-photo',
            'cta' => 'Add photo',
            'order' => 20,
        ],
        'set_privacy' => [
            'group' => self::GROUP_GET_LIVE,
            'title' => 'Set your privacy',
            'description' => 'Choose who can see your profile and whether it appears in search.',
            'required' => true,
            'action' => '/dashboard/setup/privacy',
            'cta' => 'Set privacy',
            'order' => 30,
        ],
        'publish' => [
            'group' => self::GROUP_GET_LIVE,
            'title' => 'Publish your profile',
            'description' => 'Review your poster profile and make it visible to your chosen audience.',
            'required' => true,
            'action' => '/dashboard/setup/publish',
            'cta' => 'Publish',
            'order' => 40,
        ],
        'seeking' => [
            'group' => self::GROUP_GET_MATCHED,
            'title' => 'What are you seeking?',
            'description' => 'Describe the opportunities, people or resources you are looking for.',
            'required' => false,
            'action' => '/dashboard/setup/seeking',
            'cta' => 'Add seeking',
            'order' => 50,
        ],
        'offering' => [
            'group' => self::GROUP_GET_MATCHED,
            'title' => 'What can you offer?',
            'description' => 'Share the skills, services or value you can offer others.',
            'required' => false,
            'action' => '/dashboard/setup/offering',
            'cta' => 'Add offering',
            'order' => 60,
        ],
        'contact_options' => [
            'group' => self::GROUP_GET_MATCHED,
            'title' => 'Add contact options',
            'description' => 'Choose how people can reach you and who can see each detail.',
            'required' => false,
            'action' => '/dashboard/setup/contact',
            'cta' => 'Add contact options',
            'order' => 70,
        ],
        'add_business_profile' => [
            'group' => self::GROUP_GROW,
            'title' => 'Add a business profile',
            'description' => 'Create a separate business identity with its own page and contact details.',
            'required' => false,
            'action' => '/dashboard/setup/business',
            'cta' => 'Add business profile',
            'order' => 80,
        ],
    ];

    public function ensureTasks(User $user): void
    {
        $existing = $user->onboardingTasks()->pluck('task_key')->all();

        foreach (array_keys(self::TASKS) as $key) {
            if (!in_array($key, $existing, true)) {
                $user->onboardingTasks()->create([
                    'task_key' => $key,
                    'state' => OnboardingTask::STATE_TODO,
                ]);
            }
        }
    }

    /**
     * Reconcile task state with actual profile data. Explicitly skipped tasks
     * stay in Later until their data is provided.
     */
    public function syncDerivedState(User $user): void
    {
        $this->ensureTasks($user);

        $profile = $user->defaultProfile();
        $conditions = [
            'build_profile' => $profile !== null && trim((string) $profile->title) !== '',
            'add_photo' => $profile !== null && $profile->getMedia('avatar')->isNotEmpty(),
            'set_privacy' => $profile !== null && $profile->privacy_confirmed_at !== null,
            'publish' => $profile !== null && (bool) $profile->is_published,
            'seeking' => $this->hasSeeking($profile),
            'offering' => $this->hasOffering($profile),
            'contact_options' => $profile !== null && $this->hasContactOptions($profile),
            'add_business_profile' => $user->profiles()->where('profile_type', 'business')->exists(),
        ];

        $tasks = $user->onboardingTasks()->get()->keyBy('task_key');

        foreach ($conditions as $key => $done) {
            $task = $tasks->get($key);
            if (!$task) {
                continue;
            }

            if ($done && $task->state !== OnboardingTask::STATE_DONE) {
                $task->forceFill([
                    'state' => OnboardingTask::STATE_DONE,
                    'completed_at' => $task->completed_at ?? now(),
                    'skipped_at' => null,
                ])->save();
            } elseif (!$done && $task->state === OnboardingTask::STATE_DONE) {
                $task->forceFill([
                    'state' => OnboardingTask::STATE_TODO,
                    'completed_at' => null,
                ])->save();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $this->syncDerivedState($user);

        $tasks = $user->onboardingTasks()->get()->keyBy('task_key');
        $goals = $user->onboarding_goals ?? [];
        $goalKeys = array_keys(array_filter(self::GOALS, fn ($goal, $key) => in_array($key, $goals, true), ARRAY_FILTER_USE_BOTH));
        $definitions = $this->orderedDefinitions($goals);
        $items = [];

        foreach ($definitions as $key => $definition) {
            $task = $tasks->get($key);
            $state = $task?->state ?? OnboardingTask::STATE_TODO;

            $items[$key] = [
                'key' => $key,
                'title' => $definition['title'],
                'description' => $definition['description'],
                'group' => $definition['group'],
                'group_label' => $this->groupLabel($definition['group']),
                'required' => $definition['required'],
                'action' => $definition['action'],
                'cta' => $definition['cta'],
                'state' => $state,
                'completed_at' => $task?->completed_at?->toISOString(),
                'skipped_at' => $task?->skipped_at?->toISOString(),
            ];
        }

        $required = array_filter($items, fn ($task) => $task['required']);
        $requiredDone = count(array_filter($required, fn ($task) => $task['state'] === OnboardingTask::STATE_DONE));
        $requiredSkipped = count(array_filter($required, fn ($task) => $task['state'] === OnboardingTask::STATE_SKIPPED));
        $optional = array_filter($items, fn ($task) => !$task['required']);
        $optionalDone = count(array_filter($optional, fn ($task) => $task['state'] === OnboardingTask::STATE_DONE));
        $optionalRemaining = count(array_filter($optional, fn ($task) => $task['state'] === OnboardingTask::STATE_TODO));
        $requiredRemaining = count(array_filter($required, fn ($task) => $task['state'] === OnboardingTask::STATE_TODO));

        $groups = [];
        foreach ([self::GROUP_GET_LIVE, self::GROUP_GET_MATCHED, self::GROUP_GROW] as $group) {
            $groups[] = [
                'key' => $group,
                'label' => $this->groupLabel($group),
                'tasks' => array_values(array_filter($items, fn ($task) => $task['group'] === $group)),
            ];
        }

        $completed = array_values(array_filter($items, fn ($task) => $task['state'] === OnboardingTask::STATE_DONE));
        $skipped = array_values(array_filter($items, fn ($task) => $task['state'] === OnboardingTask::STATE_SKIPPED));
        $setupCardDismissed = $user->setup_card_dismissed_at !== null;
        $nextTask = $this->nextTask($items, $requiredRemaining, $setupCardDismissed);

        $requiredTotal = count($required);
        $percent = $requiredTotal === 0
            ? 100
            : (int) round(($requiredDone / $requiredTotal) * 100);

        if ($requiredRemaining === 0 && $user->onboarding_completed_at === null) {
            $user->forceFill(['onboarding_completed_at' => now()])->save();
            $user->refresh();
        }

        return [
            'tasks' => array_values($items),
            'groups' => $groups,
            'completed_tasks' => $completed,
            'skipped_tasks' => $skipped,
            'next_task' => $nextTask,
            'progress' => [
                'required_total' => $requiredTotal,
                'required_done' => $requiredDone,
                'required_skipped' => $requiredSkipped,
                'required_remaining' => $requiredRemaining,
                'optional_total' => count($optional),
                'optional_done' => $optionalDone,
                'optional_remaining' => $optionalRemaining,
                'percent' => $percent,
            ],
            'required_complete' => $requiredRemaining === 0,
            'setup_card_visible' => !$setupCardDismissed
                && ($requiredRemaining > 0 || $optionalRemaining > 0),
            'setup_card_dismissed' => $setupCardDismissed,
            'goals' => $goals,
            'goal_options' => collect(self::GOALS)->map(fn ($goal, $key) => [
                'key' => $key,
                'label' => $goal['label'],
            ])->values()->all(),
            'goals_dismissed' => $user->onboarding_goals_dismissed_at !== null,
            'goal_prefill' => $goalKeys,
        ];
    }

    public function updateTask(User $user, string $taskKey, string $state): array
    {
        if (!array_key_exists($taskKey, self::TASKS)) {
            throw ValidationException::withMessages(['task' => 'Unknown onboarding task.']);
        }

        if (!in_array($state, OnboardingTask::states(), true)) {
            throw ValidationException::withMessages(['state' => 'Unknown onboarding task state.']);
        }

        $this->ensureTasks($user);

        $task = $user->onboardingTasks()->where('task_key', $taskKey)->firstOrFail();

        $task->forceFill([
            'state' => $state,
            'completed_at' => $state === OnboardingTask::STATE_DONE ? now() : null,
            'skipped_at' => $state === OnboardingTask::STATE_SKIPPED ? now() : null,
        ])->save();

        return $this->status($user);
    }

    /**
     * @param  array<int, string>  $goals
     */
    public function saveGoals(User $user, array $goals): array
    {
        $allowed = array_keys(self::GOALS);
        $invalid = array_values(array_diff($goals, $allowed));

        if ($invalid !== []) {
            throw ValidationException::withMessages(['goals' => 'Unknown onboarding goal selected.']);
        }

        $user->forceFill([
            'onboarding_goals' => array_values(array_unique($goals)),
            'onboarding_goals_dismissed_at' => now(),
        ])->save();

        $this->applyGoalPrefill($user, $goals);

        return $this->status($user);
    }

    public function dismissGoals(User $user): array
    {
        $user->forceFill(['onboarding_goals_dismissed_at' => now()])->save();

        return $this->status($user);
    }

    public function dismissSetupCard(User $user): array
    {
        $user->forceFill(['setup_card_dismissed_at' => now()])->save();

        return $this->status($user);
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function nextTask(array $items, int $requiredRemaining, bool $setupCardDismissed): ?array
    {
        if ($requiredRemaining > 0) {
            foreach ($items as $task) {
                if ($task['required'] && $task['state'] === OnboardingTask::STATE_TODO) {
                    return $task;
                }
            }
        }

        if ($setupCardDismissed) {
            return null;
        }

        foreach ($items as $task) {
            if (!$task['required'] && $task['state'] === OnboardingTask::STATE_TODO) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $goals
     * @return array<string, array<string, mixed>>
     */
    private function orderedDefinitions(array $goals): array
    {
        $boost = [];
        if (in_array('find_opportunities', $goals, true) || in_array('hire_talent', $goals, true)) {
            $boost['seeking'] = -15;
            $boost['offering'] = -10;
        }
        if (in_array('showcase_work', $goals, true)) {
            $boost['build_profile'] = -5;
            $boost['add_photo'] = -5;
        }
        if (in_array('grow_business', $goals, true)) {
            $boost['add_business_profile'] = -20;
        }
        if (in_array('build_network', $goals, true)) {
            $boost['contact_options'] = -10;
        }

        $definitions = [];
        foreach (self::TASKS as $key => $definition) {
            $definition['_sort'] = $definition['order'] + ($boost[$key] ?? 0);
            $definitions[$key] = $definition;
        }

        uasort($definitions, fn ($a, $b) => $a['_sort'] <=> $b['_sort']);

        return $definitions;
    }

    private function groupLabel(string $group): string
    {
        return match ($group) {
            self::GROUP_GET_LIVE => 'Get live',
            self::GROUP_GET_MATCHED => 'Get matched',
            self::GROUP_GROW => 'Grow',
            default => ucfirst(str_replace('_', ' ', $group)),
        };
    }

    private function hasSeeking(?Profile $profile): bool
    {
        if (!$profile) {
            return false;
        }

        return trim((string) $profile->seeking) !== ''
            || $profile->categories()->where('kind', 'seeking')->exists();
    }

    private function hasOffering(?Profile $profile): bool
    {
        if (!$profile) {
            return false;
        }

        return trim((string) $profile->offering) !== ''
            || $profile->categories()->where('kind', 'offering')->exists();
    }

    private function hasContactOptions(Profile $profile): bool
    {
        return filled($profile->phone)
            || filled($profile->whatsapp_phone)
            || filled($profile->contact_email)
            || filled($profile->website_url);
    }

    /**
     * @param  array<int, string>  $goals
     */
    private function applyGoalPrefill(User $user, array $goals): void
    {
        $profile = $user->defaultProfile();
        if (!$profile) {
            return;
        }

        $seeking = null;
        $offering = null;

        foreach ($goals as $goal) {
            $definition = self::GOALS[$goal] ?? null;
            if (!$definition) {
                continue;
            }
            $seeking ??= $definition['seeking'] ?? null;
            $offering ??= $definition['offering'] ?? null;
        }

        $updates = [];
        if ($seeking !== null && trim((string) $profile->seeking) === '') {
            $updates['seeking'] = $seeking;
        }
        if ($offering !== null && trim((string) $profile->offering) === '') {
            $updates['offering'] = $offering;
        }

        if ($updates !== []) {
            $profile->update($updates);
        }
    }
}
