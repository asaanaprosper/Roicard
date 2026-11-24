<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Services\SearchService;
use App\Services\EntitlementService;
use App\Services\EntitlementUsageService;
use App\Services\Notifications\AdminAlertService;
use App\Support\NotificationType;
use App\Support\PlanLimitKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SearchController extends Controller
{
    public function __construct(
        protected SearchService $search,
        protected EntitlementUsageService $usage,
        protected EntitlementService $entitlements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'result_type' => ['nullable', 'string', Rule::in(['person', 'business'])],
            'category' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'community' => ['nullable', 'string', 'max:120'],
            'organization' => ['nullable', 'string', 'max:120'],
            'organization_id' => ['nullable', 'integer', 'exists:communities,id'],
            'verified' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . (int) config('roicard.search.per_page_max', 50)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->usage->consume(
            $request->user(),
            PlanLimitKey::SEARCHES_PER_DAY,
            $this->entitlements,
        );

        $results = $this->search->search($request->user(), $validated);

        app(\App\Services\AnalyticsService::class)->record($request->user()->id, 'search_query', [
            'filters' => array_keys(array_filter($validated, fn ($value) => $value !== null && $value !== '')),
            'results' => $results->total(),
        ]);

        $this->maybeAlertSearchAbuse($request->user()->id);

        return response()->json($results);
    }

    protected function maybeAlertSearchAbuse(int $userId): void
    {
        $threshold = config('roicard.search.abuse_alert_threshold');

        if ($threshold === null || $threshold === '') {
            return;
        }

        $count = AnalyticsEvent::query()
            ->where('member_id', $userId)
            ->where('type', 'search_query')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($count < (int) $threshold) {
            return;
        }

        $cacheKey = 'search:abuse-alert:' . $userId . ':' . now()->format('YmdH');

        if (! Cache::add($cacheKey, true, now()->addHour())) {
            return;
        }

        Log::warning('search.abuse_alert', [
            'member_id' => $userId,
            'searches_last_hour' => $count,
            'threshold' => (int) $threshold,
        ]);

        app(\App\Services\AuditLogger::class)->log(
            'search.abuse_alert',
            \App\Models\User::find($userId),
            [
                'searches_last_hour' => $count,
                'threshold' => (int) $threshold,
            ],
        );

        app(AdminAlertService::class)->send(
            NotificationType::ADMIN_SCRAPING,
            'Unusual search volume detected',
            'A member crossed the configured hourly search threshold.',
            [
                'member_id' => $userId,
                'searches_last_hour' => $count,
                'threshold' => (int) $threshold,
            ],
        );
    }
}
