<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\AdminAlertNotification;
use App\Support\NotificationType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class AdminAlertService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function send(string $type, string $title, string $body, array $context = []): void
    {
        if (! NotificationType::isAdmin($type)) {
            throw new \InvalidArgumentException('Unknown admin alert type.');
        }

        $admins = User::query()
            ->where('role', 'admin')
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        try {
            Notification::send($admins, new AdminAlertNotification(
                $type,
                $title,
                $body,
                $context,
                $this->actionUrl($type),
            ));
        } catch (\Throwable $e) {
            // Operational alerts must never break the business action.
            Log::warning('admin_alert.delivery_failed', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function actionUrl(string $type): string
    {
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');

        return match ($type) {
            NotificationType::ADMIN_REFERRAL_REWARD_CAP => $frontend . '/admin/referrals',
            default => $frontend . '/admin/statistics',
        };
    }
}
