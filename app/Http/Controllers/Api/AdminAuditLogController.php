<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin view of the reusable audit trail (admin + security-sensitive events).
 */
class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:100'],
            'target_type' => ['nullable', 'string', 'max:100'],
            'target_id' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AdminActionLog::query()
            ->with([
                'actor:id,first_name,last_name,email',
                'targetUser:id,first_name,last_name,email',
            ]);

        if (! empty($validated['actor_id'])) {
            $query->where('actor_id', $validated['actor_id']);
        }

        if (! empty($validated['action'])) {
            $query->where('action', 'like', '%' . addcslashes($validated['action'], '%_\\') . '%');
        }

        if (! empty($validated['target_type'])) {
            $query->where('target_type', $validated['target_type']);
        }

        if (! empty($validated['target_id'])) {
            $query->where('target_id', (string) $validated['target_id']);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        if (! empty($validated['q'])) {
            $like = '%' . addcslashes(trim($validated['q']), '%_\\') . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('action', 'like', $like)
                    ->orWhere('target_type', 'like', $like)
                    ->orWhere('target_id', 'like', $like);
            });
        }

        $logs = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 30));

        return response()->json($logs);
    }
}
