<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\BillingEvent;
use App\Models\Connection;
use App\Models\User;

/**
 * Builds a member-owned data export without exposing filesystem paths,
 * credentials, moderation notes or other members' private contact details.
 */
class AccountDataExportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $user->load([
            'plan:id,key,name',
            'subscriptions.plan:id,key,name',
            'communityMemberships.community:id,name,slug,type',
            'socialAccounts:id,user_id,provider,email,created_at',
            'onboardingTasks',
        ]);

        return [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'status' => $user->status,
                'role' => $user->role,
                'plan' => $user->plan?->only(['id', 'key', 'name']),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'onboarding_completed_at' => $user->onboarding_completed_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'legacy_member' => (bool) $user->legacy_member,
                'privacy_consent_at' => $user->privacy_consent_at?->toIso8601String(),
                'privacy_consent_version' => $user->privacy_consent_version,
                'ai_consent_at' => $user->ai_consent_at?->toIso8601String(),
                'ai_consent_version' => $user->ai_consent_version,
            ],
            'profiles' => $this->profiles($user),
            'connections' => $this->connections($user),
            'blocks' => $this->blocks($user),
            'reports' => $this->reports($user),
            'payments' => $user->payments()
                ->orderBy('id')
                ->get()
                ->map(fn ($payment) => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                    'purpose' => $payment->purpose,
                    'method' => $payment->method,
                    'provider' => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'created_at' => $payment->created_at?->toIso8601String(),
                ])->values()->all(),
            'subscriptions' => $user->subscriptions->map(fn ($subscription) => [
                'id' => $subscription->id,
                'plan' => $subscription->plan?->only(['id', 'key', 'name']),
                'status' => $subscription->status,
                'provider' => $subscription->provider,
                'current_period_start' => $subscription->current_period_start?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
                'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
                'ended_at' => $subscription->ended_at?->toIso8601String(),
                'created_at' => $subscription->created_at?->toIso8601String(),
            ])->values()->all(),
            'billing_events' => BillingEvent::where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (BillingEvent $event) => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'provider' => $event->provider,
                    'payload' => $event->payload,
                    'processed_at' => $event->processed_at?->toIso8601String(),
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values()->all(),
            'referrals' => $this->referrals($user),
            'communities' => $user->communityMemberships->map(fn ($membership) => [
                'community' => $membership->community?->only(['id', 'name', 'slug', 'type']),
                'role' => $membership->role,
                'status' => $membership->status,
                'join_method' => $membership->join_method,
                'discoverable' => (bool) $membership->discoverable,
                'joined_at' => $membership->joined_at?->toIso8601String(),
                'starts_at' => $membership->starts_at?->toIso8601String(),
                'ends_at' => $membership->ends_at?->toIso8601String(),
                'affiliation_confirmed_at' => $membership->affiliation_confirmed_at?->toIso8601String(),
                'member_accepted_at' => $membership->member_accepted_at?->toIso8601String(),
            ])->values()->all(),
            'social_accounts' => $user->socialAccounts->map(fn ($account) => [
                'provider' => $account->provider,
                'email' => $account->email,
                'created_at' => $account->created_at?->toIso8601String(),
            ])->values()->all(),
            'onboarding_tasks' => $user->onboardingTasks->map(fn ($task) => [
                'task_key' => $task->task_key,
                'state' => $task->state,
                'completed_at' => $task->completed_at?->toIso8601String(),
                'skipped_at' => $task->skipped_at?->toIso8601String(),
            ])->values()->all(),
            'meeting_types' => $user->meetingTypes()
                ->get()
                ->map(fn ($type) => $type->only([
                    'id', 'name', 'description', 'duration_minutes', 'format',
                    'location_detail', 'is_active', 'created_at', 'updated_at',
                ]))->values()->all(),
            'meeting_bookings_hosted' => $user->hostedBookings()
                ->get()
                ->map(fn ($booking) => $booking->only([
                    'id', 'meeting_type_id', 'guest_name', 'guest_email', 'start_time',
                    'end_time', 'status', 'created_at', 'updated_at',
                ]))->values()->all(),
            'ai_usage' => AiUsageLog::where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (AiUsageLog $log) => [
                    'id' => $log->id,
                    'feature' => $log->feature,
                    'provider' => $log->provider,
                    'model' => $log->model,
                    'input_tokens' => $log->input_tokens,
                    'output_tokens' => $log->output_tokens,
                    'total_tokens' => $log->total_tokens,
                    'outcome' => $log->outcome,
                    'error_code' => $log->error_code,
                    'estimated_cost_usd' => $log->estimated_cost_usd,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])->values()->all(),
            'analytics_events' => $user->analyticsEvents()
                ->orderBy('id')
                ->get()
                ->map(fn ($event) => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'metadata' => $event->metadata,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function profiles(User $user): array
    {
        return $user->profiles()
            ->with([
                'education',
                'experience',
                'achievements',
                'socialLinks',
                'categories:id,name,kind,slug',
                'tags:id,name',
                'interestOptions:id,name',
                'signals.categories:id,name,kind,slug',
                'signals.tags:id,name',
                'catalogItems.category:id,name,slug',
                'media',
            ])
            ->orderBy('id')
            ->get()
            ->map(function ($profile) {
                return [
                    'id' => $profile->id,
                    'profile_type' => $profile->profile_type,
                    'display_name' => $profile->display_name,
                    'title' => $profile->title,
                    'role_description' => $profile->role_description,
                    'organisation' => $profile->organisation,
                    'location' => $profile->location,
                    'bio' => $profile->bio,
                    'seeking' => $profile->seeking,
                    'offering' => $profile->offering,
                    'skills' => $profile->skills,
                    'services' => $profile->services,
                    'website_url' => $profile->website_url,
                    'phone' => $profile->phone,
                    'whatsapp_phone' => $profile->whatsapp_phone,
                    'contact_email' => $profile->contact_email,
                    'date_of_birth' => $profile->date_of_birth?->toDateString(),
                    'gender' => $profile->gender,
                    'slug' => $profile->slug,
                    'visibility' => $profile->visibility,
                    'is_searchable' => (bool) $profile->is_searchable,
                    'is_contactable' => (bool) $profile->is_contactable,
                    'connection_request_policy' => $profile->connection_request_policy,
                    'contact_details_visibility' => $profile->contact_details_visibility,
                    'contact_visibility' => $profile->contact_visibility,
                    'is_default' => (bool) $profile->is_default,
                    'is_published' => (bool) $profile->is_published,
                    'published_at' => $profile->published_at?->toIso8601String(),
                    'privacy_confirmed_at' => $profile->privacy_confirmed_at?->toIso8601String(),
                    'completion_pct' => (int) $profile->completion_pct,
                    'ai_draft' => $profile->ai_draft,
                    'ai_approved_fields' => $profile->ai_approved_fields,
                    'ai_catalog_draft' => $profile->ai_catalog_draft,
                    'categories' => $profile->categories->map->only(['id', 'name', 'kind', 'slug'])->values()->all(),
                    'tags' => $profile->tags->map->only(['id', 'name'])->values()->all(),
                    'interests' => $profile->interestOptions->pluck('name')->values()->all(),
                    'education' => $profile->education->toArray(),
                    'experience' => $profile->experience->toArray(),
                    'achievements' => $profile->achievements->toArray(),
                    'social_links' => $profile->socialLinks->map->only(['platform', 'value'])->values()->all(),
                    'signals' => $profile->signals->map(fn ($signal) => [
                        'id' => $signal->id,
                        'type' => $signal->type,
                        'description' => $signal->description,
                        'is_active' => (bool) $signal->is_active,
                        'source' => $signal->source,
                        'categories' => $signal->categories->map->only(['id', 'name', 'kind', 'slug'])->values()->all(),
                        'tags' => $signal->tags->map->only(['id', 'name'])->values()->all(),
                        'created_at' => $signal->created_at?->toIso8601String(),
                    ])->values()->all(),
                    'catalog_items' => $profile->catalogItems->map(fn ($item) => [
                        'id' => $item->id,
                        'type' => $item->type,
                        'title' => $item->title,
                        'description' => $item->description,
                        'category' => $item->category?->only(['id', 'name', 'slug']),
                        'price' => $item->price,
                        'price_mode' => $item->price_mode,
                        'currency' => $item->currency,
                        'cta_type' => $item->cta_type,
                        'is_active' => (bool) $item->is_active,
                        'sort_order' => (int) $item->sort_order,
                    ])->values()->all(),
                    'media' => $profile->media->map(fn ($media) => [
                        'id' => $media->id,
                        'collection' => $media->collection_name,
                        'file_name' => $media->file_name,
                        'mime_type' => $media->mime_type,
                        'size' => $media->size,
                        'created_at' => $media->created_at?->toIso8601String(),
                        'url' => $media->collection_name === 'cv'
                            ? url("/api/profiles/{$profile->id}/cv/{$media->id}")
                            : $media->getUrl(),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function connections(User $user): array
    {
        return Connection::query()
            ->where('member_id', $user->id)
            ->orWhere('guest_user_id', $user->id)
            ->with(['member:id,first_name,last_name', 'guestUser:id,first_name,last_name'])
            ->orderBy('id')
            ->get()
            ->map(function (Connection $connection) use ($user) {
                $other = (int) $connection->member_id === (int) $user->id
                    ? $connection->guestUser
                    : $connection->member;

                return [
                    'id' => $connection->id,
                    'other_member' => $other?->only(['id', 'first_name', 'last_name']),
                    'request_type' => $connection->request_type,
                    'status' => $connection->status,
                    'member_note' => $connection->member_note,
                    'match_reason' => $connection->match_reason,
                    'created_at' => $connection->created_at?->toIso8601String(),
                    'responded_at' => $connection->responded_at?->toIso8601String(),
                    'withdrawn_at' => $connection->withdrawn_at?->toIso8601String(),
                    'removed_at' => $connection->removed_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function blocks(User $user): array
    {
        return $user->blocksMade()
            ->with('blocked:id,first_name,last_name')
            ->orderBy('id')
            ->get()
            ->map(fn ($block) => [
                'blocked_member' => $block->blocked?->only(['id', 'first_name', 'last_name']),
                'reason' => $block->reason,
                'created_at' => $block->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function reports(User $user): array
    {
        return $user->reportsMade()
            ->with('reportedUser:id,first_name,last_name')
            ->orderBy('id')
            ->get()
            ->map(fn ($report) => [
                'reported_member' => $report->reportedUser?->only(['id', 'first_name', 'last_name']),
                'reason_category' => $report->reason_category,
                'description' => $report->description,
                'status' => $report->status,
                'created_at' => $report->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function referrals(User $user): array
    {
        $received = $user->referralReceived;

        return [
            'code' => $user->referral_code,
            'referred_by_member' => $user->referrer?->only(['id', 'first_name', 'last_name']),
            'made' => $user->referralsMade()
                ->with('invitee:id,first_name,last_name')
                ->orderBy('id')
                ->get()
                ->map(fn ($referral) => [
                    'id' => $referral->id,
                    'invitee' => $referral->invitee?->only(['id', 'first_name', 'last_name']),
                    'status' => $referral->status,
                    'source' => $referral->source,
                    'attributed_at' => $referral->attributed_at?->toIso8601String(),
                    'expires_at' => $referral->expires_at?->toIso8601String(),
                    'qualified_at' => $referral->qualified_at?->toIso8601String(),
                ])->values()->all(),
            'received' => $received
                ? [
                    'id' => $received->id,
                    'referrer' => $received->referrer?->only(['id', 'first_name', 'last_name']),
                    'status' => $received->status,
                    'attributed_at' => $received->attributed_at?->toIso8601String(),
                ]
                : null,
            'reward_ledger' => $user->referralRewardEntries()
                ->orderBy('id')
                ->get()
                ->map(fn ($entry) => [
                    'id' => $entry->id,
                    'referral_id' => $entry->referral_id,
                    'entry_type' => $entry->entry_type,
                    'status' => $entry->status,
                    'amount' => $entry->amount,
                    'delta_amount' => $entry->delta_amount,
                    'currency' => $entry->currency,
                    'note' => $entry->note,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
