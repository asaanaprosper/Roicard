<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RecordAnalyticsJob;
use App\Models\Profile;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ProfileVisibilityService;
use App\Support\BearerViewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicProfileController extends Controller
{
    public function __construct(
        protected AnalyticsService $analyticsService,
        protected ProfileVisibilityService $visibility,
    ) {}

    public function show(string $slug, Request $request): JsonResponse
    {
        $profile = Profile::where('slug', $slug)->with('user')->first();

        if (! $profile) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        $viewer = $this->viewer($request);
        $isOwnerPreview = $viewer !== null
            && ((string) $viewer->getKey() === (string) $profile->user_id || $viewer->isAdmin());

        // A profile must be explicitly published to have a public page. The
        // owner (and admins) may preview an unpublished draft; everyone else
        // receives 404, so drafts never leak through the public URL.
        if ((! $profile->is_live || ! $profile->is_published) && ! $isOwnerPreview) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        if (! $profile->user || $profile->user->trashed() || $profile->user->isDeactivated()) {
            return response()->json([
                'message' => 'This profile is not yet available',
                'status' => 'draft',
            ], 403);
        }

        if (! $isOwnerPreview && ! $this->visibility->canView($viewer, $profile)) {
            return response()->json([
                'message' => 'This profile is not available to you.',
                'status' => 'restricted',
            ], 403);
        }

        $cacheKey = Profile::PUBLIC_CACHE_PREFIX . $slug;

        $data = Cache::remember($cacheKey, 3600, function () use ($slug) {
            $profile = Profile::where('slug', $slug)
                ->with([
                    'user:id,first_name,last_name,email,status,plan_id,deactivated_at,suspended_by_admin,legacy_member',
                    'user.plan.entitlements',
                    'user.subscriptions',
                    'socialLinks',
                    'education',
                    'experience',
                    'achievements',
                    'interestOptions:id,name',
                    'activeSeekingSignals.categories:id,name,kind',
                    'activeOfferingSignals.categories:id,name,kind',
                    'activeCatalogItems.category:id,name,slug',
                    'activeCatalogItems.media',
                    'user.publicOrganizations',
                    'user.publicOrganizations.media',
                ])
                ->first();

            if (! $profile) {
                return null;
            }

            $cv = $profile->getMedia('cv')->first();
            $cvIsPublic = $cv && $cv->getCustomProperty('visible') === true;
            $avatar = $profile->avatar_url;

            // Load active meeting types with availability and custom questions
            $meetingTypes = \App\Models\MeetingType::where('user_id', $profile->user_id)
                ->where('is_active', true)
                ->with(['availability', 'customQuestions'])
                ->orderBy('sort_order')
                ->get()
                ->map(fn (\App\Models\MeetingType $type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'description' => $type->description,
                    'duration_minutes' => $type->duration_minutes,
                    'format' => $type->format,
                    'format_label' => $type->format_label,
                    'location_detail' => $type->location_detail,
                    'custom_questions' => $type->customQuestions->map(fn ($q) => [
                        'id' => $q->id,
                        'question' => $q->question,
                        'is_required' => $q->is_required,
                    ]),
                ]);

            return [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'slug' => $profile->slug,
                'profile_type' => $profile->profile_type,
                'display_name' => $profile->display_name,
                'visibility' => $profile->visibility,
                'is_searchable' => (bool) $profile->is_searchable,
                'is_published' => (bool) $profile->is_published,
                'published_at' => $profile->published_at?->toISOString(),
                'title' => $profile->title,
                'role_description' => $profile->role_description,
                'organisation' => $profile->organisation,
                'whatsapp_phone' => $profile->whatsapp_phone,
                'phone' => $profile->phone,
                'contact_email' => $profile->contact_email ?: $profile->user->email,
                'website_url' => $profile->website_url,
                'skills' => $profile->skills ?? [],
                'services' => $profile->services ?? [],
                'date_of_birth' => $profile->date_of_birth?->toDateString(),
                'gender' => $profile->gender,
                'interests' => $profile->interestOptions->pluck('name')->all(),
                'location' => $profile->location,
                'bio' => $profile->bio,
                'seeking' => $profile->seeking,
                'offering' => $profile->offering,
                'verified_member' => app(\App\Services\EntitlementService::class)->hasVerificationMark($profile->user),
                'verification_label' => app(\App\Services\EntitlementService::class)->verificationLabel(),
                'seeking_items' => $profile->activeSeekingSignals->map(fn ($signal) => [
                    'id' => $signal->id,
                    'description' => $signal->description,
                    'categories' => $signal->categories->map(fn ($category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                    ])->values()->all(),
                ])->values()->all(),
                'offering_items' => $profile->activeOfferingSignals->map(fn ($signal) => [
                    'id' => $signal->id,
                    'description' => $signal->description,
                    'categories' => $signal->categories->map(fn ($category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                    ])->values()->all(),
                ])->values()->all(),
                'logo_url' => $profile->logo_url,
                'cover_image_url' => $profile->cover_url,
                'catalog_items' => $profile->activeCatalogItems->map(fn ($item) => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'title' => $item->title,
                    'description' => $item->description,
                    'category' => $item->category ? [
                        'id' => $item->category->id,
                        'name' => $item->category->name,
                        'slug' => $item->category->slug,
                    ] : null,
                    'price' => $item->price,
                    'price_mode' => $item->price_mode,
                    'price_label' => $item->priceLabel(),
                    'currency' => $item->currency,
                    'cta_type' => $item->cta_type,
                    'sort_order' => $item->sort_order,
                    'images' => $item->getMedia('images')->map(fn ($media) => [
                        'id' => $media->id,
                        'url' => $media->getUrl(),
                        'name' => $media->name,
                    ])->values()->all(),
                ])->values()->all(),
                'organizations' => $profile->user->publicOrganizations->map(fn ($organization) => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'industry' => $organization->industry,
                    'logo_url' => $organization->logo_url,
                    'role' => $organization->pivot?->role,
                ])->values()->all(),
                'avatar' => $avatar,
                'user' => [
                    'first_name' => $profile->user->first_name,
                    'last_name' => $profile->user->last_name,
                    'email' => $profile->contact_email ?: $profile->user->email,
                ],
                'social_links' => $profile->socialLinks,
                'education' => $profile->education,
                'experience' => $profile->experience,
                'achievements' => $profile->achievements,
                'cv' => $cvIsPublic ? [
                    'url' => url("/api/profiles/{$profile->id}/cv/{$cv->id}"),
                    'name' => $cv->file_name,
                    'size_kb' => (int) round($cv->size / 1024),
                ] : null,
                'meeting_types' => $meetingTypes,
            ];
        });

        if (! $data) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        // Contact details are redacted per field after the shared cache is
        // read. This keeps the hot cache public-safe while honouring
        // per-field contact visibility.
        $visibleContactFields = $this->visibility->visibleContactFields($viewer, $profile);
        if (! $visibleContactFields['phone']) {
            $data['phone'] = null;
        }
        if (! $visibleContactFields['whatsapp']) {
            $data['whatsapp_phone'] = null;
        }
        if (! $visibleContactFields['email']) {
            $data['contact_email'] = null;
            if (isset($data['user']['email'])) {
                $data['user']['email'] = null;
            }
        }
        if (! $visibleContactFields['website']) {
            $data['website_url'] = null;
        }

        if (is_array($data['social_links'] ?? null)) {
            $data['social_links'] = array_values(array_filter(
                $data['social_links'],
                fn ($link) => ($link['platform'] ?? null) !== 'website' || $visibleContactFields['website']
            ));
        }

        $data['visible_contact_fields'] = $visibleContactFields;
        $data['contact_details_visible'] = count(array_filter($visibleContactFields)) > 0;
        $data['can_contact'] = $this->visibility->canContact($viewer, $profile);

        // Skip analytics when the request is a server-side metadata/OG-image
        // fetch (identified by the _metadata query flag) or when the profile
        // owner is viewing their own profile.
        $ownerUserId = (int) $profile->user_id;

        if (! $request->boolean('_metadata') && ! $this->isSelfView($request, $ownerUserId)) {
            $source = (string) $request->input('src', 'link');
            if (! in_array($source, ['link', 'search', 'discover', 'qr', 'signature', 'tap'], true)) {
                $source = 'link';
            }

            RecordAnalyticsJob::dispatch($ownerUserId, 'profile_view', [
                'source' => $source,
                'profile_id' => $profile->id,
            ]);
        }

        return response()->json($data);
    }

    /**
     * Resolve an optional viewer even on routes without auth middleware, so
     * restricted profiles can be opened by legitimate signed-in members.
     */
    protected function viewer(Request $request): ?User
    {
        // The public route has no Sanctum middleware. Only a full-session
        // token may act as a viewer; a two-factor-pending token must not.
        return BearerViewer::resolve($request) ?? $request->user();
    }

    public function trackEvent(string $slug, Request $request): JsonResponse
    {
        $profile = Profile::where('slug', $slug)
            ->where('is_live', true)
            ->where('is_published', true)
            ->firstOrFail();
        $viewer = $this->viewer($request);

        if (! $this->visibility->canView($viewer, $profile)) {
            return response()->json(['message' => 'This profile is not available to you.'], 403);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:contact_save,whatsapp_tap'],
        ]);

        // Skip self-tracking — owner actions on their own profile should not
        // count as engagement events.
        if (! $this->isSelfView($request, (int) $profile->user_id)) {
            RecordAnalyticsJob::dispatch(
                $profile->user_id,
                $validated['type']
            );
        }

        return response()->json(['message' => 'Event recorded']);
    }

    /**
     * Determine whether the incoming request is from the profile owner
     * viewing their own profile. Resolves the user from the Sanctum
     * Bearer token (the frontend attaches it to every API call via
     * the axios interceptor, even on this public route).
     */
    protected function isSelfView(Request $request, int $ownerUserId): bool
    {
        $user = BearerViewer::resolve($request);

        return $user && (int) $user->id === $ownerUserId;
    }

    /**
     * Public sitemap feed — slugs of all publicly viewable and searchable
     * profiles (same visibility rule as show(), minus restricted profiles).
     * Consumed by the Next.js sitemap route; cached for an hour and busted
     * alongside the per-profile cache.
     */
    public function sitemap(): JsonResponse
    {
        $profiles = Cache::remember('public_profiles_sitemap', 3600, function () {
            return Profile::query()
                ->join('users', 'users.id', '=', 'profiles.user_id')
                ->whereNull('users.deleted_at')
                ->whereNull('users.deactivated_at')
                ->where('profiles.is_live', true)
                ->where('profiles.is_published', true)
                ->where('profiles.visibility', \App\Support\ProfileVisibilityOptions::VISIBILITY_PUBLIC)
                ->where('profiles.is_searchable', true)
                ->whereNotNull('profiles.slug')
                ->orderBy('profiles.id')
                ->get(['profiles.slug', 'profiles.updated_at'])
                ->map(fn ($p) => [
                    'slug' => $p->slug,
                    'lastmod' => $p->updated_at?->toISOString(),
                ])
                ->all();
        });

        return response()->json(['data' => $profiles]);
    }
}
