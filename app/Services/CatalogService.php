<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Profile;
use App\Models\ProfileSignal;
use App\Support\PlanLimitKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CatalogService
{
    public function __construct(protected EntitlementService $entitlements) {}

    /**
     * @return array<string, mixed>
     */
    public function list(Profile $profile): array
    {
        $items = $profile->catalogItems()
            ->with(['category:id,name,slug,kind', 'media'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $active = $items->where('is_active', true)->count();

        return [
            'items' => $items->map(fn (CatalogItem $item) => $this->payload($item))->values()->all(),
            'counts' => [
                'total' => $items->count(),
                'active' => $active,
            ],
            'limits' => [
                'catalog_items' => $this->entitlements->limit($profile->user, PlanLimitKey::CATALOG_ITEMS),
            ],
            'max_images' => max(1, (int) config('roicard.catalog.max_images_per_item', 5)),
            'currency' => config('roicard.catalog.default_currency', 'GHS'),
        ];
    }

    public function create(Profile $profile, array $data): CatalogItem
    {
        $this->assertBusiness($profile);
        $this->assertCategory($data['category_id'] ?? null);

        $active = (bool) ($data['is_active'] ?? true);

        if ($active) {
            $this->assertActiveLimit($profile);
        }

        $item = DB::transaction(function () use ($profile, $data, $active) {
            $item = $profile->catalogItems()->create([
                'type' => $data['type'] ?? CatalogItem::TYPE_SERVICE,
                'title' => trim((string) $data['title']),
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'price' => $data['price'] ?? null,
                'price_mode' => $data['price_mode'] ?? CatalogItem::PRICE_ON_REQUEST,
                'currency' => strtoupper($data['currency'] ?? config('roicard.catalog.default_currency', 'GHS')),
                'cta_type' => $data['cta_type'] ?? CatalogItem::CTA_ENQUIRE,
                'is_active' => $active,
                'sort_order' => (int) ($data['sort_order'] ?? ($profile->catalogItems()->max('sort_order') + 1)),
            ]);

            $this->syncSignal($item);
            $this->invalidate($profile);

            return $item->fresh()->load(['category:id,name,slug,kind', 'media']);
        });

        return $item;
    }

    public function update(Profile $profile, CatalogItem $item, array $data): CatalogItem
    {
        $this->assertBusiness($profile);
        $this->assertOwns($profile, $item);

        if (array_key_exists('category_id', $data)) {
            $this->assertCategory($data['category_id']);
        }

        $nextActive = array_key_exists('is_active', $data)
            ? (bool) $data['is_active']
            : $item->is_active;

        if ($nextActive && ! $item->is_active) {
            $this->assertActiveLimit($profile);
        }

        DB::transaction(function () use ($item, $profile, $data, $nextActive) {
            $item->fill([
                'type' => $data['type'] ?? $item->type,
                'title' => array_key_exists('title', $data) ? trim((string) $data['title']) : $item->title,
                'description' => array_key_exists('description', $data) ? $data['description'] : $item->description,
                'category_id' => array_key_exists('category_id', $data) ? $data['category_id'] : $item->category_id,
                'price' => array_key_exists('price', $data) ? $data['price'] : $item->price,
                'price_mode' => $data['price_mode'] ?? $item->price_mode,
                'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $item->currency,
                'cta_type' => $data['cta_type'] ?? $item->cta_type,
                'is_active' => $nextActive,
                'sort_order' => array_key_exists('sort_order', $data)
                    ? max(0, (int) $data['sort_order'])
                    : $item->sort_order,
            ])->save();

            $this->syncSignal($item);
            $this->invalidate($profile);
        });

        return $item->fresh()->load(['category:id,name,slug,kind', 'media']);
    }

    public function setActive(Profile $profile, CatalogItem $item, bool $active): CatalogItem
    {
        return $this->update($profile, $item, ['is_active' => $active]);
    }

    public function reorder(Profile $profile, array $itemIds): void
    {
        $this->assertBusiness($profile);
        $ownedIds = $profile->catalogItems()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $requested = array_values(array_unique(array_map('intval', $itemIds)));

        foreach ($requested as $id) {
            if (! in_array($id, $ownedIds, true)) {
                throw ValidationException::withMessages([
                    'items' => 'One or more catalog items do not belong to this profile.',
                ]);
            }
        }

        DB::transaction(function () use ($requested) {
            foreach ($requested as $position => $id) {
                CatalogItem::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        $this->invalidate($profile);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function addImages(Profile $profile, CatalogItem $item, array $files): CatalogItem
    {
        $this->assertOwns($profile, $item);

        $max = max(1, (int) config('roicard.catalog.max_images_per_item', 5));
        $existing = $item->getMedia('images')->count();

        if ($existing + count($files) > $max) {
            throw ValidationException::withMessages([
                'images' => "You can upload at most {$max} images per catalog item.",
            ]);
        }

        foreach ($files as $file) {
            $item->addMedia($file)->toMediaCollection('images');
        }

        $profile->bustPublicCache();
        $this->invalidate($profile);

        return $item->fresh()->load(['category:id,name,slug,kind', 'media']);
    }

    public function removeImage(Profile $profile, CatalogItem $item, Media $media): CatalogItem
    {
        $this->assertOwns($profile, $item);
        abort_unless(
            $media->model_type === CatalogItem::class && (int) $media->model_id === (int) $item->id,
            404
        );

        $media->delete();

        $profile->bustPublicCache();
        $this->invalidate($profile);

        return $item->fresh()->load(['category:id,name,slug,kind', 'media']);
    }

    public function delete(Profile $profile, CatalogItem $item): void
    {
        $this->assertOwns($profile, $item);

        DB::transaction(function () use ($profile, $item) {
            ProfileSignal::where('catalog_item_id', $item->id)->delete();
            $item->clearMediaCollection('images');
            $item->delete();
            $this->invalidate($profile);
        });
    }

    /**
     * Keep exactly one system-managed Offering signal per active catalog item.
     * Inactive items pause their signal; deleted items remove it.
     */
    public function syncSignal(CatalogItem $item): void
    {
        $signal = ProfileSignal::firstOrNew(['catalog_item_id' => $item->id]);

        if (! $item->is_active) {
            if ($signal->exists) {
                $signal->forceFill([
                    'is_active' => false,
                    'paused_at' => now(),
                ])->save();
            }

            return;
        }

        $description = trim($item->title);
        if ($item->description) {
            $suffix = Str::limit(trim($item->description), 160);
            if ($suffix !== '') {
                $description .= ': ' . $suffix;
            }
        }

        $signal->fill([
            'profile_id' => $item->profile_id,
            'type' => ProfileSignal::TYPE_OFFERING,
            'description' => $description !== '' ? $description : $item->title,
            'is_active' => true,
            'paused_at' => null,
            'source' => 'catalog',
            'sort_order' => $item->sort_order,
        ])->save();

        $signal->categories()->sync($item->category_id ? [(int) $item->category_id] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(CatalogItem $item): array
    {
        return [
            'id' => $item->id,
            'profile_id' => $item->profile_id,
            'type' => $item->type,
            'title' => $item->title,
            'description' => $item->description,
            'category_id' => $item->category_id,
            'category' => $item->category ? [
                'id' => $item->category->id,
                'name' => $item->category->name,
                'slug' => $item->category->slug,
            ] : null,
            'price' => $item->price,
            'price_mode' => $item->price_mode,
            'price_label' => $item->priceLabel(),
            'currency' => $item->currency,
            'cta_type' => $item->cta_type,
            'is_active' => $item->is_active,
            'sort_order' => $item->sort_order,
            'images' => $item->getMedia('images')->map(fn ($media) => [
                'id' => $media->id,
                'url' => $media->getUrl(),
                'thumb_url' => $media->getUrl(),
                'name' => $media->name,
            ])->values()->all(),
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }

    protected function assertActiveLimit(Profile $profile): void
    {
        $active = $profile->catalogItems()->where('is_active', true)->count();

        $this->entitlements->ensureWithinLimit(
            $profile->user,
            PlanLimitKey::CATALOG_ITEMS,
            $active,
            1,
            'Your plan does not allow another active catalog item.',
        );
    }

    protected function assertCategory(?int $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        $category = Category::find($categoryId);

        if (! $category || $category->kind !== Category::KIND_OFFERING) {
            throw ValidationException::withMessages([
                'category_id' => 'Choose an offering category for this catalog item.',
            ]);
        }
    }

    protected function assertBusiness(Profile $profile): void
    {
        abort_unless($profile->isBusiness(), 422, 'Catalog items are only available on business profiles.');
    }

    protected function assertOwns(Profile $profile, CatalogItem $item): void
    {
        abort_unless((int) $item->profile_id === (int) $profile->id, 404);
    }

    protected function invalidate(Profile $profile): void
    {
        $profile->bustPublicCache();
        Cache::increment('matchmaking:version');
    }
}
