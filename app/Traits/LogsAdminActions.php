<?php

namespace App\Traits;

use App\Models\User;
use App\Services\AuditLogger;

trait LogsAdminActions
{
    /**
     * @param  array<string, mixed>  $metadata  Structured context (never secrets
     *                                          or message bodies).
     */
    protected function logAdminAction(string $action, ?int $targetUserId = null, array $metadata = []): void
    {
        $target = $targetUserId ? User::find($targetUserId) : null;

        app(AuditLogger::class)->log($action, $target, $metadata);
    }
}
