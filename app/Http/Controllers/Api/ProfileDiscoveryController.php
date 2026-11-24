<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Services\ProfileVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Member discovery. The visibility + searchability scopes are applied in the
 * database, so a profile that is not discoverable can never appear here even
 * if a client tampers with filters.
 */
class ProfileDiscoveryController extends Controller
{
    public function __construct(protected ProfileVisibilityService $visibility) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:personal,business'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Profile::query()
            ->with([
                'user:id,first_name,last_name,status',
                'categories:id,name,kind,slug',
                'media',
            ]);

        $this->visibility->applyVisibilityScope($query, $request->user());
        $this->visibility->applySearchableScope($query);

        if (! empty($validated['type'])) {
            $query->where('profiles.profile_type', $validated['type']);
        }

        if (! empty($validated['q'])) {
            $term = trim($validated['q']);
            $like = '%' . addcslashes($term, '%_\\') . '%';

            $query->where(function ($builder) use ($like) {
                $builder->where('profiles.display_name', 'like', $like)
                    ->orWhere('profiles.title', 'like', $like)
                    ->orWhere('profiles.organisation', 'like', $like)
                    ->orWhere('profiles.bio', 'like', $like);
            });
        }

        $perPage = max(1, min(
            (int) ($validated['per_page'] ?? 20),
            (int) config('roicard.search.per_page_max', 50),
        ));
        $maxCandidates = max(1, (int) config('roicard.search.max_candidates', 200));

        $candidates = $query
            ->orderByDesc('profiles.is_default')
            ->orderBy('profiles.id')
            ->limit($maxCandidates)
            ->get();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = $candidates->slice(($page - 1) * $perPage, $perPage)->values();

        $profiles = new LengthAwarePaginator(
            $items,
            $candidates->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );

        $profiles->getCollection()->transform(fn (Profile $profile) => [
            'id' => $profile->id,
            'slug' => $profile->slug,
            'profile_type' => $profile->profile_type,
            'display_name' => $profile->display_name,
            'title' => $profile->title,
            'organisation' => $profile->organisation,
            'location' => $profile->location,
            'bio' => $profile->bio,
            'avatar_url' => $profile->getFirstMediaUrl('avatar') ?: null,
            'visibility' => $profile->visibility,
            'categories' => $profile->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
            ]),
            'user' => $profile->user?->only(['first_name', 'last_name']),
        ]);

        return response()->json($profiles);
    }
}
