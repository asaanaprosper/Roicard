<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberReport;
use App\Notifications\ReportOutcomeNotification;
use App\Services\AuditLogger;
use App\Services\MemberAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                MemberReport::STATUS_PENDING,
                MemberReport::STATUS_REVIEWED,
                MemberReport::STATUS_ACTIONED,
                MemberReport::STATUS_DISMISSED,
            ])],
            'reason_category' => ['nullable', 'string', Rule::in(MemberReport::REASONS)],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MemberReport::query()
            ->with([
                'reporter:id,first_name,last_name,email',
                'reportedUser:id,first_name,last_name,email',
                'profile:id,user_id,slug,display_name,title,organisation',
                'connection:id,member_id,guest_user_id,status,request_type',
                'reviewer:id,first_name,last_name',
            ])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['reason_category'] ?? null, fn ($q, $reason) => $q->where('reason_category', $reason));

        if (!empty($validated['q'])) {
            $like = '%' . addcslashes(trim($validated['q']), '%_\\') . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('description', 'like', $like)
                    ->orWhereHas('reportedUser', fn ($user) => $user->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like))
                    ->orWhereHas('reporter', fn ($user) => $user->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
            });
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate((int) ($validated['per_page'] ?? 20))
        );
    }

    public function show(MemberReport $report): JsonResponse
    {
        return response()->json([
            'report' => $report->load([
                'reporter:id,first_name,last_name,email',
                'reportedUser:id,first_name,last_name,email,status',
                'profile:id,user_id,slug,display_name,title,organisation',
                'connection:id,member_id,guest_user_id,status,request_type,member_note,match_reason',
                'reviewer:id,first_name,last_name',
            ]),
        ]);
    }

    public function update(
        Request $request,
        MemberReport $report,
        AuditLogger $audit,
        MemberAccountService $accounts,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                MemberReport::STATUS_REVIEWED,
                MemberReport::STATUS_ACTIONED,
                MemberReport::STATUS_DISMISSED,
            ])],
            'action' => ['nullable', 'string', Rule::in(['review', 'dismiss', 'suspend_user', 'no_action'])],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $action = $validated['action'] ?? 'review';
        $previousStatus = $report->status;

        if ($action === 'suspend_user') {
            $reported = $report->reportedUser;
            if ($reported) {
                $accounts->suspend($reported, true);

                // Keep the suspension decision visible in the audit trail
                // independently of the report-status change.
                $audit->log('moderation.member_suspended', $reported, [
                    'report_id' => $report->id,
                    'reason_category' => $report->reason_category,
                ]);
            }
            $validated['status'] = MemberReport::STATUS_ACTIONED;
        } elseif ($action === 'dismiss') {
            $validated['status'] = MemberReport::STATUS_DISMISSED;
        }

        $report->fill([
            'status' => $validated['status'],
            'admin_notes' => $validated['admin_notes'] ?? $report->admin_notes,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $audit->log('moderation.report_updated', $report, [
            'action' => $action,
            'status' => $report->status,
            'reported_user_id' => $report->reported_user_id,
        ]);

        if ($previousStatus !== $report->status && $report->reporter) {
            $report->reporter->notify(new ReportOutcomeNotification($report->fresh()));
        }

        return response()->json([
            'report' => $report->fresh()->load(['reporter:id,first_name,last_name,email', 'reportedUser:id,first_name,last_name,email,status']),
            'message' => 'Moderation report updated.',
        ]);
    }
}
