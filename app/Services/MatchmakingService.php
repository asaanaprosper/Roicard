<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Connection;
use App\Models\Profile;
use App\Models\ProfileSignal;
use App\Models\RecommendationDismissal;
use App\Models\RecommendationEvent;
use App\Models\RecommendationFeedback;
use App\Models\User;
use App\Support\PlanLimitKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class MatchmakingService
{
    public function __construct(
        protected EntitlementService $entitlements,
        protected ProfileVisibilityService $visibility,
        protected BlockService $blocks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function recommendations(User $user, Profile $profile, ?int $limit = null): array
    {
        $limit ??= $this->recommendationLimit($user);
        $version = (int) Cache::get('matchmaking:version', 0);
        $cacheKey = $this->cacheKey($profile, $version, $limit);

        $payload = Cache::remember(
            $cacheKey,
            max(60, (int) config('roicard.matchmaking.cache_ttl_seconds', 600)),
            fn () => $this->computeRecommendations($user, $profile, $limit)
        );

        // An impression is recorded for each return, even when the computed
        // recommendations came from the short-lived cache.
        foreach ($payload['items'] as $item) {
            RecommendationEvent::create([
                'profile_id' => $profile->id,
                'recommended_profile_id' => $item['profile']['id'],
                'event_type' => 'impression',
                'score' => $item['score'],
                'reason_codes' => $item['reason_codes'],
                'metadata' => [
                    'matched_items' => $item['matched_items'],
                ],
            ]);
        }

        return $payload;
    }

    /**
     * Precompute and cache recommendations without recording impressions.
     * Used by the scheduled daily refresh.
     *
     * @return array<string, mixed>
     */
    public function warmRecommendations(User $user, Profile $profile): array
    {
        $limit = $this->recommendationLimit($user);
        $version = (int) Cache::get('matchmaking:version', 0);

        $payload = $this->computeRecommendations($user, $profile, $limit);

        Cache::put(
            $this->cacheKey($profile, $version, $limit),
            $payload,
            max(60, (int) config('roicard.matchmaking.cache_ttl_seconds', 600)),
        );

        return $payload;
    }

    protected function cacheKey(Profile $profile, int $version, int $limit): string
    {
        return "matchmaking:recommendations:profile:{$profile->id}:v{$version}:limit:{$limit}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function computeRecommendations(User $user, Profile $profile, int $limit): array
    {
        $profile->loadMissing([
            'activeSeekingSignals.categories',
            'activeSeekingSignals.tags',
            'activeOfferingSignals.categories',
            'activeOfferingSignals.tags',
            'categories',
            'user.publicOrganizations',
        ]);

        $seeking = $profile->activeSeekingSignals;
        $offering = $profile->activeOfferingSignals;

        if ($seeking->isEmpty() && $offering->isEmpty()) {
            return [
                'items' => [],
                'total' => 0,
                'needs_signals' => true,
                'missing_signals' => ['seeking', 'offering'],
                'message' => 'Add at least one Seeking or Offering item to see recommendations.',
            ];
        }

        if ($limit <= 0) {
            return [
                'items' => [],
                'total' => 0,
                'needs_signals' => false,
                'missing_signals' => [],
                'message' => 'Recommendations are not enabled on your current plan.',
            ];
        }

        $blocked = $this->blocks->blockedUserIds($user);
        $connectedUserIds = $this->connectedOrPendingUserIds($user);
        $dismissedProfileIds = RecommendationDismissal::query()
            ->where('profile_id', $profile->id)
            ->active()
            ->pluck('recommended_profile_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $feedback = RecommendationFeedback::where('profile_id', $profile->id)
            ->get()
            ->groupBy('recommended_profile_id')
            ->map(fn (Collection $rows) => $rows->pluck('penalty', 'category_pair_key')->all());

        $candidates = Profile::query()
            ->with([
                'user:id,first_name,last_name,status,email_verified_at,created_at,plan_id,deactivated_at',
                'user.plan.entitlements',
                'user.subscriptions',
                'user.publicOrganizations',
                'media',
                'categories:id,name,kind',
                'activeSeekingSignals.categories',
                'activeSeekingSignals.tags',
                'activeOfferingSignals.categories',
                'activeOfferingSignals.tags',
                'activeCatalogItems.category:id,name,slug',
            ])
            ->whereKeyNot($profile->id)
            ->where('user_id', '!=', $user->id)
            ->where('is_searchable', true)
            ->whereHas('signals', fn ($query) => $query->where('is_active', true))
            ->when($blocked !== [], fn ($query) => $query->whereNotIn('user_id', $blocked))
            ->when($connectedUserIds !== [], fn ($query) => $query->whereNotIn('user_id', $connectedUserIds))
            ->when($dismissedProfileIds !== [], fn ($query) => $query->whereNotIn('id', $dismissedProfileIds));

        $this->visibility->applyVisibilityScope($candidates, $user);

        $candidates = $candidates
            ->limit((int) config('roicard.search.max_candidates', 200))
            ->get();

        $minimum = (float) config('roicard.matchmaking.minimum_score', 25);
        $scored = [];

        foreach ($candidates as $candidate) {
            $result = $this->scoreCandidate($profile, $candidate, $seeking, $offering, $feedback->get($candidate->id, []));
            if ($result === null || $result['score'] < $minimum) {
                continue;
            }
            $scored[] = $result;
        }

        usort($scored, function ($a, $b) {
            return [$b['score'], $b['profile']['completion_pct'], $a['profile']['id']]
                <=> [$a['score'], $a['profile']['completion_pct'], $b['profile']['id']];
        });

        $cap = max(1, (int) config('roicard.matchmaking.diversity_cap_per_organization', 2));
        $orgCounts = [];
        $items = [];

        foreach ($scored as $item) {
            $orgKey = $this->normaliseOrganization($item['profile']['organisation']);
            if ($orgKey !== '') {
                $count = $orgCounts[$orgKey] ?? 0;
                if ($count >= $cap) {
                    continue;
                }
                $orgCounts[$orgKey] = $count + 1;
            }

            $items[] = $item;
            if (count($items) >= $limit) {
                break;
            }
        }

        return [
            'items' => $items,
            'total' => count($items),
            'needs_signals' => false,
            'missing_signals' => array_values(array_filter([
                $seeking->isEmpty() ? 'seeking' : null,
                $offering->isEmpty() ? 'offering' : null,
            ])),
            'message' => $items === []
                ? 'No strong matches yet. Add more detail to your Seeking and Offering items, or check back later.'
                : null,
        ];
    }

    /**
     * Whether two profiles have at least one matching Seeking/Offering
     * vocabulary pair. Used by the "matching only" connection-request policy.
     */
    public function profilesHaveMatchingSignals(Profile $first, Profile $second): bool
    {
        $relations = [
            'activeSeekingSignals.categories',
            'activeSeekingSignals.tags',
            'activeOfferingSignals.categories',
            'activeOfferingSignals.tags',
        ];

        $first->loadMissing($relations);
        $second->loadMissing($relations);

        foreach ($first->activeSeekingSignals as $need) {
            foreach ($second->activeOfferingSignals as $offer) {
                if ($this->signalMatch($need, $offer, []) !== null) {
                    return true;
                }
            }
        }

        foreach ($first->activeOfferingSignals as $offer) {
            foreach ($second->activeSeekingSignals as $need) {
                if ($this->signalMatch($need, $offer, []) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, ProfileSignal>  $seeking
     * @param  Collection<int, ProfileSignal>  $offering
     * @param  array<string, float>  $feedback
     * @return array<string, mixed>|null
     */
    protected function scoreCandidate(
        Profile $profile,
        Profile $candidate,
        Collection $seeking,
        Collection $offering,
        array $feedback,
    ): ?array {
        $weights = config('roicard.matchmaking.weights', []);
        $score = 0.0;
        $reasonCodes = [];
        $matched = [];
        $pairKeys = [];
        $directions = [];

        foreach ($seeking as $need) {
            foreach ($candidate->activeOfferingSignals as $offer) {
                $match = $this->signalMatch($need, $offer, $feedback);
                if ($match === null) {
                    continue;
                }

                $score += (float) ($weights['offering_meets_seeking'] ?? 40);
                $reasonCodes[] = 'offering_meets_seeking';
                $score += $match['tag_overlap'] ? (float) ($weights['tag_overlap'] ?? 10) : 0;
                if ($match['tag_overlap']) {
                    $reasonCodes[] = 'tag_overlap';
                }
                $score -= $match['penalty'];
                $pairKeys = array_merge($pairKeys, $match['pair_keys']);
                $directions['offering_meets_seeking'] = true;
                $matched[] = $this->matchedItemPayload('offering_meets_seeking', $need, $offer, $match);
            }
        }

        foreach ($offering as $offer) {
            foreach ($candidate->activeSeekingSignals as $need) {
                $match = $this->signalMatch($need, $offer, $feedback);
                if ($match === null) {
                    continue;
                }

                $score += (float) ($weights['seeking_meets_offering'] ?? 40);
                $reasonCodes[] = 'seeking_meets_offering';
                $score += $match['tag_overlap'] ? (float) ($weights['tag_overlap'] ?? 10) : 0;
                if ($match['tag_overlap']) {
                    $reasonCodes[] = 'tag_overlap';
                }
                $score -= $match['penalty'];
                $pairKeys = array_merge($pairKeys, $match['pair_keys']);
                $directions['seeking_meets_offering'] = true;
                $matched[] = $this->matchedItemPayload('seeking_meets_offering', $need, $offer, $match);
            }
        }

        if (count($directions) === 2) {
            $score += (float) ($weights['mutual_bonus'] ?? 15);
            $reasonCodes[] = 'mutual_match';
        }

        if ($matched === []) {
            return null;
        }

        $candidateOrg = $this->normaliseOrganization($candidate->organisation);
        $profileOrg = $this->normaliseOrganization($profile->organisation);
        $sharedVerifiedOrganization = array_intersect(
            $profile->user?->publicOrganizations?->pluck('id')->all() ?? [],
            $candidate->user?->publicOrganizations?->pluck('id')->all() ?? [],
        ) !== [];

        if (
            ($candidateOrg !== '' && $profileOrg !== '' && $candidateOrg === $profileOrg)
            || $sharedVerifiedOrganization
        ) {
            $score += (float) ($weights['same_organization'] ?? 5);
            $reasonCodes[] = 'same_organization';
        }

        $locationMatch = $this->locationMatch($profile->location, $candidate->location);
        if ($locationMatch !== null) {
            $score += (float) ($weights['same_location'] ?? 5);
            $reasonCodes[] = $locationMatch;
        }

        $completion = max(0, min(100, (int) $candidate->completion_pct));
        $score += ((float) ($weights['profile_completeness'] ?? 8)) * ($completion / 100);
        if ($completion >= 50) {
            $reasonCodes[] = 'complete_profile';
        }

        $reasonCodes = array_values(array_unique($reasonCodes));
        $score = round(max(0, $score), 2);

        return [
            'profile' => $this->profilePayload($candidate),
            'score' => $score,
            'reason_codes' => $reasonCodes,
            'explanation' => $this->explanation($matched, $reasonCodes, $candidate, $profile),
            'matched_items' => $matched,
            'category_pair_keys' => array_values(array_unique($pairKeys)),
        ];
    }

    /**
     * A match is based on shared structured vocabulary. Seeking and offering
     * categories live in separate kind taxonomies, so the comparison uses a
     * stable category key (name/slug) rather than raw category ids. Shared
     * tags are also sufficient vocabulary for a match.
     *
     * @param  array<string, float>  $feedback
     * @return array{tag_overlap: bool, penalty: float, pair_keys: array<int, string>, category_keys: array<int, string>, shared_category_ids: array<int, int>, shared_tags: array<int, int>}|null
     */
    protected function signalMatch(ProfileSignal $need, ProfileSignal $offer, array $feedback): ?array
    {
        $needKeys = $this->categoryKeys($need);
        $offerKeys = $this->categoryKeys($offer);
        $sharedCategoryKeys = array_values(array_intersect_key($needKeys, $offerKeys));

        $needTags = $need->tags->pluck('id')->map(fn ($id) => (int) $id)->all();
        $offerTags = $offer->tags->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sharedTags = array_values(array_intersect($needTags, $offerTags));

        if ($sharedCategoryKeys === [] && $sharedTags === []) {
            return null;
        }

        $pairKeys = [];
        foreach ($sharedCategoryKeys as $key) {
            $pairKeys[] = 'category:' . $key;
        }
        foreach ($sharedTags as $tagId) {
            $pairKeys[] = 'tag:' . $tagId;
        }

        $sharedCategoryIds = [];
        foreach ($sharedCategoryKeys as $key) {
            foreach ($need->categories as $category) {
                if ($this->categoryKey($category) === $key) {
                    $sharedCategoryIds[] = (int) $category->id;
                }
            }
        }

        $penalty = 0.0;
        foreach ($pairKeys as $key) {
            $penalty += (float) ($feedback[$key] ?? 0);
        }

        return [
            'tag_overlap' => $sharedTags !== [],
            'penalty' => $penalty,
            'pair_keys' => $pairKeys,
            'category_keys' => $sharedCategoryKeys,
            'shared_category_ids' => array_values(array_unique($sharedCategoryIds)),
            'shared_tags' => $sharedTags,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function categoryKeys(ProfileSignal $signal): array
    {
        $keys = [];

        foreach ($signal->categories as $category) {
            $keys[$this->categoryKey($category)] = (int) $category->id;
        }

        return $keys;
    }

    protected function categoryKey(Category $category): string
    {
        return mb_strtolower(trim((string) $category->name));
    }

    /**
     * @param  array{category_keys: array<int,string>, shared_category_ids: array<int,int>, shared_tags: array<int,int>}  $match
     */
    protected function matchedItemPayload(string $direction, ProfileSignal $need, ProfileSignal $offer, array $match): array
    {
        return [
            'direction' => $direction,
            'seeking_item' => [
                'id' => $need->id,
                'description' => $need->description,
                'categories' => $need->categories->pluck('name')->values()->all(),
            ],
            'offering_item' => [
                'id' => $offer->id,
                'description' => $offer->description,
                'categories' => $offer->categories->pluck('name')->values()->all(),
            ],
            'shared_category_keys' => $match['category_keys'],
            'shared_category_ids' => $match['shared_category_ids'],
            'shared_tag_ids' => $match['shared_tags'],
        ];
    }

    protected function profilePayload(Profile $profile): array
    {
        return [
            'id' => $profile->id,
            'slug' => $profile->slug,
            'profile_type' => $profile->profile_type,
            'display_name' => $profile->display_name,
            'title' => $profile->title,
            'organisation' => $profile->organisation,
            'location' => $profile->location,
            'bio' => $profile->bio,
            'avatar_url' => $profile->getFirstMediaUrl('avatar') ?: null,
            'logo_url' => $profile->logo_url,
            'cover_image_url' => $profile->cover_url,
            'completion_pct' => (int) $profile->completion_pct,
            'categories' => $profile->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
            ])->values()->all(),
            'catalog_items' => $profile->activeCatalogItems->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'title' => $item->title,
                'description' => $item->description,
                'price_label' => $item->priceLabel(),
                'category' => $item->category?->name,
            ])->values()->all(),
            'organizations' => $profile->user?->publicOrganizations?->map(fn ($organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'industry' => $organization->industry,
                'logo_url' => $organization->logo_url,
            ])->values()->all() ?? [],
            'user' => $profile->user?->only(['first_name', 'last_name']),
            'verified' => $profile->user
                ? app(EntitlementService::class)->hasVerificationMark($profile->user)
                : false,
        ];
    }

    protected function explanation(array $matched, array $reasonCodes, Profile $candidate, Profile $requester): string
    {
        $first = $matched[0] ?? null;
        $parts = [];

        if ($first) {
            if ($first['direction'] === 'offering_meets_seeking') {
                $parts[] = 'Recommended because you are seeking “'
                    . $this->shorten($first['seeking_item']['description'])
                    . '” and this member offers “'
                    . $this->shorten($first['offering_item']['description']) . '”.';
            } else {
                $parts[] = 'Recommended because this member is seeking “'
                    . $this->shorten($first['seeking_item']['description'])
                    . '” and you offer “'
                    . $this->shorten($first['offering_item']['description']) . '”.';
            }
        }

        if (in_array('mutual_match', $reasonCodes, true)) {
            array_unshift($parts, 'Mutual fit: you can both help each other.');
        }
        if (in_array('tag_overlap', $reasonCodes, true)) {
            $parts[] = 'You also share related interests.';
        }
        if (in_array('same_organization', $reasonCodes, true)) {
            $parts[] = 'You share an organization or industry.';
        }
        if (in_array('same_city', $reasonCodes, true) || in_array('same_country', $reasonCodes, true)) {
            $parts[] = 'You are both based in the same area.';
        }

        return trim(implode(' ', $parts)) ?: 'Recommended by Roicard based on your profile signals.';
    }

    protected function shorten(string $value, int $limit = 130): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1) . '…' : $value;
    }

    protected function normaliseOrganization(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    protected function locationMatch(?string $first, ?string $second): ?string
    {
        $a = mb_strtolower(trim((string) $first));
        $b = mb_strtolower(trim((string) $second));

        if ($a === '' || $b === '') {
            return null;
        }
        if ($a === $b) {
            return 'same_city';
        }

        $aCountry = trim((string) collect(explode(',', $a))->last());
        $bCountry = trim((string) collect(explode(',', $b))->last());

        if ($aCountry !== '' && $aCountry === $bCountry) {
            return 'same_country';
        }

        return null;
    }

    protected function recommendationLimit(User $user): int
    {
        $limit = $this->entitlements->limit($user, PlanLimitKey::RECOMMENDATIONS_SHOWN);

        return $limit ?? max(1, (int) config('roicard.matchmaking.default_recommendation_limit', 20));
    }

    /**
     * @return array<int, int>
     */
    protected function connectedOrPendingUserIds(User $user): array
    {
        $ids = Connection::query()
            ->where(function ($query) use ($user) {
                $query->where('member_id', $user->id)->orWhere('guest_user_id', $user->id);
            })
            ->whereIn('status', [Connection::STATUS_PENDING, Connection::STATUS_APPROVED])
            ->get(['member_id', 'guest_user_id'])
            ->map(function (Connection $connection) use ($user) {
                return (int) $connection->member_id === (int) $user->id
                    ? (int) $connection->guest_user_id
                    : (int) $connection->member_id;
            })
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        return $ids;
    }

    public function dismiss(Profile $profile, Profile $recommended, string $reason = 'dismissed'): void
    {
        $days = max(1, (int) config('roicard.matchmaking.dismiss_days', 90));
        $now = now();

        RecommendationDismissal::updateOrCreate(
            ['profile_id' => $profile->id, 'recommended_profile_id' => $recommended->id],
            [
                'reason' => $reason,
                'dismissed_at' => $now,
                'expires_at' => $now->copy()->addDays($days),
            ],
        );

        $this->recordAction($profile, $recommended, $reason === 'not_relevant' ? 'not_relevant' : 'dismiss', [
            'days' => $days,
        ]);

        $this->invalidate();
    }

    /**
     * @param  array<int, string>  $categoryPairKeys
     */
    public function notRelevant(Profile $profile, Profile $recommended, array $categoryPairKeys = []): void
    {
        $penalty = (float) config('roicard.matchmaking.not_relevant_penalty', 10);

        foreach (array_values(array_unique($categoryPairKeys)) as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            RecommendationFeedback::updateOrCreate(
                [
                    'profile_id' => $profile->id,
                    'recommended_profile_id' => $recommended->id,
                    'category_pair_key' => $key,
                ],
                ['penalty' => $penalty],
            );
        }

        $this->dismiss($profile, $recommended, 'not_relevant');
    }

    public function recordAction(Profile $profile, Profile $recommended, string $action, array $metadata = []): void
    {
        RecommendationEvent::create([
            'profile_id' => $profile->id,
            'recommended_profile_id' => $recommended->id,
            'event_type' => $action,
            'score' => $metadata['score'] ?? null,
            'reason_codes' => $metadata['reason_codes'] ?? null,
            'metadata' => $metadata ?: null,
        ]);
    }

    public function invalidate(): void
    {
        Cache::increment('matchmaking:version');
    }
}
