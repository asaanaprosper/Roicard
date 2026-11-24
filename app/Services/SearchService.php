<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class SearchService
{
    public function __construct(protected ProfileVisibilityService $visibility) {}

    public function search(User $viewer, array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = max(1, min($perPage, (int) config('roicard.search.per_page_max', 50)));
        $term = trim((string) ($filters['q'] ?? ''));

        $query = Profile::query()
            ->with([
                'user:id,first_name,last_name,status,email_verified_at,plan_id',
                'user.plan.entitlements',
                'user.subscriptions',
                'user.communities:id,name,slug',
                'user.publicOrganizations',
                'user.publicOrganizations.media',
                'media',
                'categories:id,name,kind,slug',
                'activeCatalogItems.category:id,name,slug',
                'activeCatalogItems.media',
            ])
            ->where('user_id', '!=', $viewer->id)
            ->where('is_searchable', true)
            ->whereHas('user', fn ($user) => $user->whereNull('deactivated_at'));

        if (!empty($filters['result_type'])) {
            $type = $filters['result_type'] === 'business' ? 'business' : 'personal';
            $query->where('profile_type', $type);
        }

        if (!empty($filters['category'])) {
            $category = $filters['category'];
            $query->where(function ($builder) use ($category) {
                $builder->whereHas('categories', function ($categories) use ($category) {
                    $categories->where('categories.id', $category)
                        ->orWhere('categories.slug', $category);
                })->orWhereHas('signals', function ($signals) use ($category) {
                    $signals->where('is_active', true)
                        ->whereHas('categories', function ($categories) use ($category) {
                            $categories->where('categories.id', $category)
                                ->orWhere('categories.slug', $category);
                        });
                })->orWhereHas('activeCatalogItems', function ($items) use ($category) {
                    $items->whereHas('category', function ($categories) use ($category) {
                        $categories->where('categories.id', $category)
                            ->orWhere('categories.slug', $category);
                    });
                });
            });
        }

        if (!empty($filters['location'])) {
            $like = '%' . $this->escapeLike($filters['location']) . '%';
            $query->where('location', 'like', $like);
        }

        if (!empty($filters['organization'])) {
            $like = '%' . $this->escapeLike($filters['organization']) . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('organisation', 'like', $like)
                    ->orWhereHas('user.publicOrganizations', function ($organizations) use ($like) {
                        $organizations->where('communities.name', 'like', $like)
                            ->orWhere('communities.industry', 'like', $like);
                    });
            });
        }

        if (!empty($filters['organization_id'])) {
            $organizationId = (int) $filters['organization_id'];
            $query->whereHas('user.publicOrganizations', function ($organizations) use ($organizationId) {
                $organizations->where('communities.id', $organizationId);
            });
        }

        if (!empty($filters['community'])) {
            $community = $filters['community'];
            $query->whereHas('user.communities', function ($communities) use ($community) {
                $communities->where(function ($match) use ($community) {
                    $match->where('communities.id', $community)
                        ->orWhere('communities.slug', $community);
                });

                $this->constrainToDiscoverableMembership($communities);
            });
        }

        if (!empty($filters['community_ids']) && is_array($filters['community_ids'])) {
            $query->whereHas('user.communities', function ($communities) use ($filters) {
                $communities->whereIn('communities.id', $filters['community_ids']);
                $this->constrainToDiscoverableMembership($communities);
            });
        }

        if (!empty($filters['verified'])) {
            $query->whereHas('user', function ($user) {
                $user->where('status', 'active')
                    ->whereHas('plan', function ($plan) {
                        $plan->where('verification_mark', true)
                            ->whereNotNull('price')
                            ->where('price', '>', 0);
                    });
            });
        }

        $this->visibility->applyVisibilityScope($query, $viewer);

        // Keep a privacy-scoped copy for the typo-tolerant fallback below.
        $fallbackQuery = clone $query;

        if ($term !== '') {
            $like = '%' . $this->escapeLike($term) . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('display_name', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('organisation', 'like', $like)
                    ->orWhere('bio', 'like', $like)
                    ->orWhere('location', 'like', $like)
                    ->orWhere('seeking', 'like', $like)
                    ->orWhere('offering', 'like', $like)
                    ->orWhere('skills', 'like', $like)
                    ->orWhere('services', 'like', $like)
                    ->orWhereHas('user', function ($user) use ($like) {
                        $user->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like);
                    })
                    ->orWhereHas('signals', fn ($signals) => $signals
                        ->where('is_active', true)
                        ->where('description', 'like', $like))
                    ->orWhereHas('activeCatalogItems', function ($items) use ($like) {
                        $items->where(function ($match) use ($like) {
                            $match->where('title', 'like', $like)
                                ->orWhere('description', 'like', $like);
                        });
                    })
                    ->orWhereHas('user.publicOrganizations', function ($organizations) use ($like) {
                        $organizations->where('communities.name', 'like', $like)
                            ->orWhere('communities.industry', 'like', $like);
                    })
                    ->orWhereHas('categories', fn ($categories) => $categories->where('name', 'like', $like));
            });
        }

        $candidates = $query
            ->orderByDesc('completion_pct')
            ->orderByDesc('updated_at')
            ->limit(max(1, (int) config('roicard.search.max_candidates', 200)))
            ->get();

        if ($term !== '' && $candidates->isEmpty()) {
            $candidates = $fallbackQuery
                ->orderByDesc('completion_pct')
                ->orderByDesc('updated_at')
                ->limit(max(1, (int) config('roicard.search.max_candidates', 200)))
                ->get()
                ->filter(fn (Profile $profile) => $this->looselyMatches($profile, $term))
                ->values();
        }

        $viewerCommunityIds = $viewer->communityMemberships()
            ->active()
            ->pluck('community_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $scored = $candidates->map(function (Profile $profile) use ($term, $viewerCommunityIds) {
            $haystack = mb_strtolower(trim(implode(' ', array_filter([
                $profile->display_name,
                $profile->title,
                $profile->organisation,
                $profile->bio,
                $profile->location,
                $profile->seeking,
                $profile->offering,
                is_array($profile->skills) ? implode(' ', $profile->skills) : '',
                is_array($profile->services) ? implode(' ', $profile->services) : '',
                $profile->user?->first_name,
                $profile->user?->last_name,
                $profile->categories->pluck('name')->implode(' '),
                $profile->activeCatalogItems->pluck('title')->implode(' '),
                $profile->activeCatalogItems->pluck('description')->implode(' '),
                $profile->user?->publicOrganizations?->pluck('name')->implode(' '),
                $profile->user?->publicOrganizations?->pluck('industry')->implode(' '),
            ]))));

            $relevance = 0;
            if ($term !== '') {
                $needle = mb_strtolower($term);
                $relevance = substr_count($haystack, $needle);
                $name = mb_strtolower(trim(($profile->user?->first_name ?? '') . ' ' . ($profile->user?->last_name ?? '')));
                if ($name !== '' && str_contains($name, $needle)) {
                    $relevance += 3;
                }
            }

            $profileCommunityIds = $profile->user?->communities?->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [];
            $sharedCommunities = count(array_intersect($profileCommunityIds, $viewerCommunityIds));
            $verified = $profile->user
                ? app(EntitlementService::class)->hasVerificationMark($profile->user)
                : false;

            return [
                'profile' => $this->profilePayload($profile),
                'relevance' => $relevance,
                'shared_communities' => $sharedCommunities,
                'verified' => $verified,
                'completion_pct' => (int) $profile->completion_pct,
            ];
        })->sort(function ($a, $b) {
            return [$b['relevance'], $b['shared_communities'], (int) $b['verified'], $b['completion_pct']]
                <=> [$a['relevance'], $a['shared_communities'], (int) $a['verified'], $a['completion_pct']];
        })->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = $scored->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $scored->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    public function profilePayload(Profile $profile): array
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
                'slug' => $category->slug,
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

    /**
     * Community-scoped discovery must honour both the membership window and
     * the member's per-community discoverability choice. Without this, a
     * member who opted out of a community could still appear there whenever
     * their profile visibility was public or members-only.
     */
    protected function constrainToDiscoverableMembership($query): void
    {
        $now = now();

        $query->where('communities.status', Community::STATUS_ACTIVE)
            ->where('community_members.status', CommunityMember::STATUS_ACTIVE)
            ->where('community_members.discoverable', true)
            ->where(function ($dates) use ($now) {
                $dates->whereNull('community_members.starts_at')
                    ->orWhere('community_members.starts_at', '<=', $now);
            })
            ->where(function ($dates) use ($now) {
                $dates->whereNull('community_members.ends_at')
                    ->orWhere('community_members.ends_at', '>', $now);
            });
    }

    /**
     * Minimal typo tolerance: when the strict LIKE search finds nothing, a
     * privacy-scoped candidate set is compared with a small edit-distance
     * threshold. This keeps filters, visibility and result caps intact.
     */
    protected function looselyMatches(Profile $profile, string $term): bool
    {
        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            $profile->display_name,
            $profile->title,
            $profile->organisation,
            $profile->location,
            $profile->bio,
            $profile->seeking,
            $profile->offering,
            is_array($profile->skills) ? implode(' ', $profile->skills) : '',
            is_array($profile->services) ? implode(' ', $profile->services) : '',
            $profile->user?->first_name,
            $profile->user?->last_name,
            $profile->categories->pluck('name')->implode(' '),
            $profile->activeCatalogItems->pluck('title')->implode(' '),
            $profile->activeCatalogItems->pluck('description')->implode(' '),
            $profile->user?->publicOrganizations?->pluck('name')->implode(' '),
            $profile->user?->publicOrganizations?->pluck('industry')->implode(' '),
        ]))));

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $haystack, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($term)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) < 3) {
                continue;
            }

            $threshold = min(2, max(1, (int) floor(mb_strlen($word) / 4)));

            foreach ($tokens as $token) {
                if (abs(mb_strlen($token) - mb_strlen($word)) > $threshold) {
                    continue;
                }

                if (levenshtein($word, $token) <= $threshold) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function escapeLike(string $value): string
    {
        return addcslashes(trim($value), '%_\\');
    }
}
