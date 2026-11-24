<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Profile;
use App\Models\ProfileSignal;
use App\Models\Tag;
use App\Models\User;
use App\Support\PlanLimitKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileSignalService
{
    public function __construct(protected EntitlementService $entitlements) {}

    public function list(Profile $profile): array
    {
        $signals = $profile->signals()
            ->whereNull('catalog_item_id')
            ->with(['categories:id,name,kind', 'tags:id,name'])
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'seeking' => $signals->where('type', ProfileSignal::TYPE_SEEKING)->values(),
            'offering' => $signals->where('type', ProfileSignal::TYPE_OFFERING)->values(),
        ];
    }

    public function create(User $user, Profile $profile, array $data): ProfileSignal
    {
        $this->assertOwns($user, $profile);

        $type = $data['type'] ?? null;
        if (!in_array($type, ProfileSignal::types(), true)) {
            throw ValidationException::withMessages(['type' => 'Choose seeking or offering.']);
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages(['description' => 'Describe this item.']);
        }

        $active = (bool) ($data['is_active'] ?? true);
        if ($active) {
            $this->assertActiveLimit($profile, $type, 0);
        }

        return DB::transaction(function () use ($profile, $type, $description, $active, $data) {
            $signal = $profile->signals()->create([
                'type' => $type,
                'description' => $description,
                'is_active' => $active,
                'paused_at' => $active ? null : now(),
                'source' => 'member',
                'sort_order' => (int) ($data['sort_order'] ?? ($profile->signals()->max('sort_order') + 1)),
            ]);

            $this->syncCategories($signal, $data['category_ids'] ?? []);
            $this->syncTags($signal, $data['tag_ids'] ?? []);
            $this->forgetRecommendations();

            return $signal->fresh()->load(['categories:id,name,kind', 'tags:id,name']);
        });
    }

    public function update(User $user, Profile $profile, ProfileSignal $signal, array $data): ProfileSignal
    {
        $this->assertOwns($user, $profile);
        abort_unless((int) $signal->profile_id === (int) $profile->id, 404);
        $this->assertMemberManaged($signal);

        if (array_key_exists('type', $data) && $data['type'] !== $signal->type) {
            throw ValidationException::withMessages(['type' => 'A signal item cannot change type.']);
        }

        $description = null;
        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            if ($description === '') {
                throw ValidationException::withMessages(['description' => 'Describe this item.']);
            }
        }

        $nextActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $signal->is_active;
        if ($nextActive && !$signal->is_active) {
            $this->assertActiveLimit($profile, $signal->type, 0);
        }

        return DB::transaction(function () use ($signal, $description, $nextActive, $data) {
            $signal->fill([
                'description' => $description ?? $signal->description,
                'is_active' => $nextActive,
                'paused_at' => $nextActive ? null : ($signal->paused_at ?? now()),
            ]);

            if (array_key_exists('sort_order', $data)) {
                $signal->sort_order = max(0, (int) $data['sort_order']);
            }

            $signal->save();

            if (array_key_exists('category_ids', $data)) {
                $this->syncCategories($signal, $data['category_ids']);
            }
            if (array_key_exists('tag_ids', $data)) {
                $this->syncTags($signal, $data['tag_ids']);
            }

            $this->forgetRecommendations();

            return $signal->fresh()->load(['categories:id,name,kind', 'tags:id,name']);
        });
    }

    public function delete(User $user, Profile $profile, ProfileSignal $signal): void
    {
        $this->assertOwns($user, $profile);
        abort_unless((int) $signal->profile_id === (int) $profile->id, 404);
        $this->assertMemberManaged($signal);

        DB::transaction(function () use ($signal) {
            $signal->delete();
            $this->forgetRecommendations();
        });
    }

    public function pause(User $user, Profile $profile, ProfileSignal $signal): ProfileSignal
    {
        return $this->update($user, $profile, $signal, ['is_active' => false]);
    }

    public function resume(User $user, Profile $profile, ProfileSignal $signal): ProfileSignal
    {
        return $this->update($user, $profile, $signal, ['is_active' => true]);
    }

    /**
     * Sync the first profile-level legacy signal when the older Settings form
     * edits the Phase 2 free-text fields. Member-created items are untouched.
     */
    public function syncLegacySignals(Profile $profile): void
    {
        foreach ([ProfileSignal::TYPE_SEEKING, ProfileSignal::TYPE_OFFERING] as $type) {
            $text = trim((string) ($profile->{$type} ?? ''));
            $legacy = $profile->signals()->where('type', $type)->where('source', 'legacy')->first();

            if ($legacy) {
                if ($text !== '' && $legacy->description !== $text) {
                    $legacy->forceFill(['description' => $text])->save();
                }
                continue;
            }

            if ($text !== '' && $profile->signals()->where('type', $type)->doesntExist()) {
                $profile->signals()->create([
                    'type' => $type,
                    'description' => $text,
                    'is_active' => true,
                    'source' => 'legacy',
                    'sort_order' => 0,
                ]);
            }
        }

        $this->forgetRecommendations();
    }

    public function activeCount(Profile $profile, string $type): int
    {
        return $profile->signals()
            ->whereNull('catalog_item_id')
            ->where('type', $type)
            ->where('is_active', true)
            ->count();
    }

    protected function assertMemberManaged(ProfileSignal $signal): void
    {
        if ($signal->catalog_item_id !== null || $signal->source === 'catalog') {
            throw ValidationException::withMessages([
                'signal' => 'This item is managed from the business catalog.',
            ]);
        }
    }

    /**
     * @param  array<int, int>  $categoryIds
     */
    protected function syncCategories(ProfileSignal $signal, array $categoryIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        $categories = Category::whereIn('id', $ids)->get();

        if ($categories->count() !== count($ids)) {
            throw ValidationException::withMessages(['category_ids' => 'One or more categories are invalid.']);
        }

        foreach ($categories as $category) {
            if ($category->kind !== $signal->type) {
                throw ValidationException::withMessages([
                    'category_ids' => 'Select categories that match the seeking/offering type.',
                ]);
            }
        }

        $signal->categories()->sync($ids);
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    protected function syncTags(ProfileSignal $signal, array $tagIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $tagIds)));
        $count = Tag::whereIn('id', $ids)->count();

        if ($count !== count($ids)) {
            throw ValidationException::withMessages(['tag_ids' => 'One or more tags are invalid.']);
        }

        $signal->tags()->sync($ids);
    }

    protected function assertActiveLimit(Profile $profile, string $type, int $additional): void
    {
        $key = $type === ProfileSignal::TYPE_SEEKING
            ? PlanLimitKey::ACTIVE_SEEKING_ITEMS
            : PlanLimitKey::ACTIVE_OFFERING_ITEMS;

        $current = $this->activeCount($profile, $type);

        $this->entitlements->ensureWithinLimit(
            $profile->user,
            $key,
            $current,
            max(1, $additional),
            $type === ProfileSignal::TYPE_SEEKING
                ? 'Your plan does not allow another active Seeking item.'
                : 'Your plan does not allow another active Offering item.',
        );
    }

    protected function assertOwns(User $user, Profile $profile): void
    {
        abort_unless((string) $profile->user_id === (string) $user->getKey(), 403, 'Profile does not belong to this account.');
    }

    public function forgetRecommendations(): void
    {
        Cache::increment('matchmaking:version');
    }
}
