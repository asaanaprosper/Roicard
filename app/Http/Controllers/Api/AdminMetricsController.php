<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\CatalogEnquiry;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Connection;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\ReferralRewardEntry;
use App\Models\SignatureCard;
use App\Models\SmartCard;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMetricsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $since = now()->subDays($days);

        $events = AnalyticsEvent::where('created_at', '>=', $since);
        $profileViews = (clone $events)->where('type', 'profile_view');

        $sources = (clone $profileViews)
            ->whereNotNull('metadata')
            ->selectRaw(
                (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite'
                    ? "COALESCE(json_extract(metadata, '$.source'), 'unknown')"
                    : "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.source')), 'unknown')") . ' as source, COUNT(*) as total'
            )
            ->groupBy('source')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['source' => $row->source, 'count' => (int) $row->total])
            ->all();

        $connectionRequests = (clone $events)->where('type', 'connection_request')->count();
        $connectionsAccepted = (clone $events)->where('type', 'connection_accepted')->count();

        $referralCounts = Referral::where('created_at', '>=', $since)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $rewardValue = (float) ReferralRewardEntry::where('created_at', '>=', $since)
            ->whereIn('status', [ReferralRewardEntry::STATUS_PENDING, ReferralRewardEntry::STATUS_AVAILABLE])
            ->sum('amount');

        return response()->json([
            'window_days' => $days,
            'users' => [
                'new_members' => \App\Models\User::where('created_at', '>=', $since)->count(),
                'active_members' => \App\Models\User::where('status', 'active')->count(),
            ],
            'profiles' => [
                'published' => \App\Models\Profile::where('is_published', true)->count(),
                'searchable' => \App\Models\Profile::where('is_searchable', true)->count(),
                'published_events' => (clone $events)->where('type', 'profile_published')->count(),
                'searchability_disabled_events' => (clone $events)->where('type', 'searchability_disabled')->count(),
            ],
            'discovery' => [
                'profile_views' => $profileViews->count(),
                'sources' => $sources,
                'qr_scans' => (clone $events)->where('type', 'qr_scan')->count(),
                'signature_scans' => (clone $events)->where('type', 'signature_scan')->count(),
                'searches' => (clone $events)->where('type', 'search_query')->count(),
            ],
            'connections' => [
                'requests' => $connectionRequests,
                'accepted' => $connectionsAccepted,
                'pending' => Connection::where('status', Connection::STATUS_PENDING)->count(),
                'accepted_total' => Connection::where('status', Connection::STATUS_APPROVED)->count(),
                'acceptance_rate' => $connectionRequests > 0
                    ? round(($connectionsAccepted / $connectionRequests) * 100, 1)
                    : null,
            ],
            'catalog' => [
                'enquiries' => CatalogEnquiry::where('created_at', '>=', $since)->count(),
                'enquiries_total' => CatalogEnquiry::count(),
                'active_items' => \App\Models\CatalogItem::where('is_active', true)->count(),
            ],
            'organizations' => [
                'verified' => Community::where('type', Community::TYPE_ORGANIZATION)
                    ->where('verification_status', Community::VERIFICATION_VERIFIED)->count(),
                'pending' => Community::where('type', Community::TYPE_ORGANIZATION)
                    ->where('verification_status', Community::VERIFICATION_PENDING)->count(),
                'affiliated_members' => CommunityMember::whereIn('community_id', Community::where('type', Community::TYPE_ORGANIZATION)->pluck('id'))
                    ->where('status', CommunityMember::STATUS_ACTIVE)
                    ->whereNotNull('affiliation_confirmed_at')
                    ->whereNotNull('member_accepted_at')
                    ->count(),
                'member_added_events' => (clone $events)->where('type', 'organization_member_invited')->count(),
                'affiliation_confirmed_events' => (clone $events)->where('type', 'organization_affiliation_confirmed')->count(),
            ],
            'referrals' => [
                'by_status' => $referralCounts,
                'signup_events' => (clone $events)->where('type', 'referral_signup')->count(),
                'qualification_events' => (clone $events)->where('type', 'referral_qualified')->count(),
                'reward_value_pending_available' => $rewardValue,
            ],
            'signature_cards' => [
                'generated_total' => SignatureCard::count(),
                'generated_events' => (clone $events)->where('type', 'signature_card_generated')->count(),
                'by_format' => SignatureCard::selectRaw('format, COUNT(*) as total')
                    ->groupBy('format')
                    ->pluck('total', 'format')
                    ->all(),
            ],
            'billing' => [
                'active_subscriptions' => Subscription::where('status', Subscription::STATUS_ACTIVE)->count(),
                'expired_subscriptions' => Subscription::where('status', Subscription::STATUS_EXPIRED)->count(),
                'successful_payments' => Payment::where('status', Payment::STATUS_SUCCESS)
                    ->where('created_at', '>=', $since)->count(),
                'subscription_payments' => Payment::where('purpose', Payment::PURPOSE_SUBSCRIPTION)
                    ->where('status', Payment::STATUS_SUCCESS)->count(),
                'card_activation_payments' => Payment::where('purpose', Payment::PURPOSE_CARD_ACTIVATION)
                    ->where('status', Payment::STATUS_SUCCESS)->count(),
                'active_cards' => SmartCard::where('inventory_status', SmartCard::STATUS_ACTIVE)->count(),
            ],
        ]);
    }
}
