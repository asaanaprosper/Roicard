<?php

namespace App\Services;

use App\Models\AdminActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Reusable audit trail for administrative and security-sensitive actions.
 *
 * Legacy columns (admin_id / target_user_id) are populated alongside the
 * canonical actor/target pair so existing admin activity feeds keep working.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        ?Model $target = null,
        array $metadata = [],
        ?User $actor = null,
    ): ?AdminActionLog {
        $actor ??= auth()->user();

        if (! $actor) {
            Log::warning('Audit event skipped because no actor was available', [
                'action' => $action,
                'target_type' => $target ? $target::class : null,
                'target_id' => $target?->getKey(),
            ]);

            return null;
        }

        $targetType = $target ? class_basename($target) : null;
        $targetId = $target?->getKey();

        return AdminActionLog::create([
            'actor_id' => $actor->getKey(),
            'admin_id' => $actor->getKey(),
            'action' => $action,
            'target_user_id' => $target instanceof User ? $target->getKey() : null,
            'target_type' => $targetType,
            'target_id' => $targetId !== null ? (string) $targetId : null,
            'metadata' => $metadata ?: null,
        ]);
    }
}
