<?php

namespace App\Services;

use App\Models\CatalogEnquiry;
use App\Models\CatalogItem;
use App\Models\User;
use App\Notifications\CatalogEnquiryReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogEnquiryService
{
    public function __construct(protected ProfileVisibilityService $visibility) {}

    public function submit(CatalogItem $item, array $data, ?User $viewer = null): CatalogEnquiry
    {
        if (! $item->is_active) {
            throw ValidationException::withMessages([
                'catalog_item' => 'This catalog item is not accepting enquiries.',
            ]);
        }

        $profile = $item->profile;

        if (! $profile || ! $this->visibility->canView($viewer, $profile)) {
            throw ValidationException::withMessages([
                'catalog_item' => 'This catalog is not available.',
            ]);
        }

        if (! $this->visibility->canContact($viewer, $profile)) {
            throw ValidationException::withMessages([
                'catalog_item' => 'This business is not accepting enquiries right now.',
            ]);
        }

        $enquiry = DB::transaction(function () use ($item, $profile, $data, $viewer) {
            $enquiry = CatalogEnquiry::create([
                'catalog_item_id' => $item->id,
                'business_profile_id' => $profile->id,
                'sender_user_id' => $viewer?->id,
                'name' => trim((string) $data['name']),
                'email' => mb_strtolower(trim((string) $data['email'])),
                'phone' => isset($data['phone']) && trim((string) $data['phone']) !== ''
                    ? trim((string) $data['phone'])
                    : null,
                'message' => trim((string) $data['message']),
                'status' => CatalogEnquiry::STATUS_NEW,
                'source' => $viewer ? 'member' : 'public',
            ]);

            $profile->user?->notify(new CatalogEnquiryReceivedNotification($enquiry));

            app(\App\Services\AnalyticsService::class)->record(
                $profile->user_id,
                'catalog_enquiry',
                ['catalog_item_id' => $item->id, 'enquiry_id' => $enquiry->id]
            );

            return $enquiry;
        });

        return $enquiry->load(['item:id,title,type', 'profile:id,slug,display_name']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forProfile(\App\Models\Profile $profile): array
    {
        return CatalogEnquiry::query()
            ->where('business_profile_id', $profile->id)
            ->with(['item:id,title,type', 'sender:id,first_name,last_name'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (CatalogEnquiry $enquiry) => [
                'id' => $enquiry->id,
                'item' => $enquiry->item ? [
                    'id' => $enquiry->item->id,
                    'title' => $enquiry->item->title,
                    'type' => $enquiry->item->type,
                ] : null,
                'sender_user_id' => $enquiry->sender_user_id,
                'sender_name' => $enquiry->sender
                    ? trim($enquiry->sender->first_name . ' ' . $enquiry->sender->last_name)
                    : null,
                'name' => $enquiry->name,
                'email' => $enquiry->email,
                'phone' => $enquiry->phone,
                'message' => $enquiry->message,
                'status' => $enquiry->status,
                'source' => $enquiry->source,
                'created_at' => $enquiry->created_at,
            ])
            ->values()
            ->all();
    }

    public function markRead(\App\Models\Profile $profile, CatalogEnquiry $enquiry): CatalogEnquiry
    {
        abort_unless((int) $enquiry->business_profile_id === (int) $profile->id, 404);

        $enquiry->forceFill(['status' => CatalogEnquiry::STATUS_READ])->save();

        return $enquiry->fresh();
    }
}
