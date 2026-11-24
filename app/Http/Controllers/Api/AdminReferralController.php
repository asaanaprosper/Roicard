<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Services\AuditLogger;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminReferralController extends Controller
{
    public function index(Request $request, ReferralService $referrals): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                Referral::STATUS_ATTRIBUTED,
                Referral::STATUS_QUALIFIED,
                Referral::STATUS_AVAILABLE,
                Referral::STATUS_PAID,
                Referral::STATUS_VOIDED,
                Referral::STATUS_EXPIRED,
                Referral::STATUS_CAPPED,
            ])],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Referral::query()
            ->with([
                'referrer:id,first_name,last_name,email',
                'invitee:id,first_name,last_name,email',
                'rewardEntries',
            ])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        if (! empty($validated['q'])) {
            $like = '%' . addcslashes(trim($validated['q']), '%_\\') . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('referral_code', 'like', $like)
                    ->orWhereHas('referrer', fn ($user) => $user
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like))
                    ->orWhereHas('invitee', fn ($user) => $user
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like));
            });
        }

        $summary = array_merge($referrals->ledgerSummary(), [
            'budget_total' => config('roicard.referrals.total_budget'),
            'budget_alert_threshold' => config('roicard.referrals.budget_alert_threshold'),
            'payout_enabled' => (bool) config('roicard.referrals.payout_enabled', false),
            'program_enabled' => (bool) config('roicard.referrals.enabled', false),
            'qualification_trigger' => config('roicard.referrals.qualification_trigger'),
            'supported_triggers' => ReferralService::SUPPORTED_TRIGGERS,
            'refund_window_days' => config('roicard.referrals.refund_window_days'),
        ]);

        $threshold = $summary['budget_alert_threshold'];
        $summary['budget_alert'] = $threshold !== null && $threshold !== ''
            && $summary['committed_value'] >= (float) $threshold;

        return response()->json([
            'referrals' => $query->orderByDesc('created_at')
                ->paginate((int) ($validated['per_page'] ?? 25)),
            'summary' => $summary,
        ]);
    }

    public function show(Referral $referral): JsonResponse
    {
        $referral->load([
            'referrer:id,first_name,last_name,email',
            'invitee:id,first_name,last_name,email,created_at',
            'rewardEntries.creator:id,first_name,last_name',
        ]);

        return response()->json(['referral' => $referral]);
    }

    public function approve(
        Request $request,
        Referral $referral,
        ReferralService $referrals,
        AuditLogger $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = $referrals->markAvailable($referral, $request->user(), $validated['note'] ?? null);

        $audit->log('referral.reward_available', $referral, [
            'entry_id' => $entry->id,
            'amount' => $entry->amount,
            'currency' => $entry->currency,
        ]);

        return response()->json([
            'referral' => $referral->fresh()->load('rewardEntries'),
            'message' => 'Referral reward marked available.',
        ]);
    }

    public function void(
        Request $request,
        Referral $referral,
        ReferralService $referrals,
        AuditLogger $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $entry = $referrals->void($referral, $request->user(), $validated['reason']);

        $audit->log('referral.reward_voided', $referral, [
            'entry_id' => $entry->id,
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'referral' => $referral->fresh()->load('rewardEntries'),
            'message' => 'Referral reward voided with an append-only ledger entry.',
        ]);
    }

    public function markPaid(
        Request $request,
        Referral $referral,
        ReferralService $referrals,
        AuditLogger $audit,
    ): JsonResponse {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:500'],
        ]);

        $entry = $referrals->markPaid($referral, $request->user(), $validated['note']);

        $audit->log('referral.reward_paid', $referral, [
            'entry_id' => $entry->id,
            'amount' => $entry->amount,
            'currency' => $entry->currency,
        ]);

        return response()->json([
            'referral' => $referral->fresh()->load('rewardEntries'),
            'message' => 'Referral reward marked paid.',
        ]);
    }
}
