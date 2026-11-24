<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\Profile;
use App\Services\CatalogEnquiryService;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class BusinessCatalogController extends Controller
{
    public function __construct(
        protected CatalogService $catalog,
        protected CatalogEnquiryService $enquiries,
    ) {}

    public function index(Profile $profile): JsonResponse
    {
        return response()->json($this->catalog->list($profile));
    }

    public function store(Request $request, Profile $profile): JsonResponse
    {
        $validated = $this->validated($request);
        $item = $this->catalog->create($profile, $validated);

        if ($request->hasFile('images')) {
            $item = $this->catalog->addImages($profile, $item, $request->file('images'));
        }

        return response()->json([
            'item' => $this->catalog->payload($item),
            'message' => 'Catalog item created.',
        ], 201);
    }

    public function update(Request $request, Profile $profile, CatalogItem $item): JsonResponse
    {
        $validated = $this->validated($request, true);
        $item = $this->catalog->update($profile, $item, $validated);

        if ($request->hasFile('images')) {
            $item = $this->catalog->addImages($profile, $item, $request->file('images'));
        }

        return response()->json([
            'item' => $this->catalog->payload($item),
            'message' => 'Catalog item updated.',
        ]);
    }

    public function activate(Profile $profile, CatalogItem $item): JsonResponse
    {
        return response()->json([
            'item' => $this->catalog->payload($this->catalog->setActive($profile, $item, true)),
            'message' => 'Catalog item activated.',
        ]);
    }

    public function deactivate(Profile $profile, CatalogItem $item): JsonResponse
    {
        return response()->json([
            'item' => $this->catalog->payload($this->catalog->setActive($profile, $item, false)),
            'message' => 'Catalog item deactivated.',
        ]);
    }

    public function destroy(Profile $profile, CatalogItem $item): JsonResponse
    {
        $this->catalog->delete($profile, $item);

        return response()->json(['message' => 'Catalog item deleted.']);
    }

    public function reorder(Request $request, Profile $profile): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['integer', 'exists:business_catalog_items,id'],
        ]);

        $this->catalog->reorder($profile, $validated['items']);

        return response()->json(['message' => 'Catalog order updated.']);
    }

    public function uploadImages(Request $request, Profile $profile, CatalogItem $item): JsonResponse
    {
        $maxKb = max(1, (int) config('roicard.catalog.max_image_kb', 5120));
        $maxFiles = max(1, (int) config('roicard.catalog.max_images_per_item', 5));

        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:' . $maxFiles],
            'images.*' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:' . $maxKb],
        ]);

        $item = $this->catalog->addImages($profile, $item, $request->file('images'));

        return response()->json([
            'item' => $this->catalog->payload($item),
            'message' => 'Images uploaded.',
        ]);
    }

    public function destroyImage(Profile $profile, CatalogItem $item, Media $media): JsonResponse
    {
        $item = $this->catalog->removeImage($profile, $item, $media);

        return response()->json([
            'item' => $this->catalog->payload($item),
            'message' => 'Image removed.',
        ]);
    }

    public function enquiries(Profile $profile): JsonResponse
    {
        return response()->json([
            'enquiries' => $this->enquiries->forProfile($profile),
        ]);
    }

    public function markEnquiryRead(Profile $profile, int $enquiry): JsonResponse
    {
        $model = $profile->catalogEnquiries()->findOrFail($enquiry);

        return response()->json([
            'enquiry' => $this->enquiries->markRead($profile, $model)->only(['id', 'status']),
            'message' => 'Enquiry marked as read.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $partial = false): array
    {
        $maxKb = max(1, (int) config('roicard.catalog.max_image_kb', 5120));
        $maxFiles = max(1, (int) config('roicard.catalog.max_images_per_item', 5));

        return $request->validate([
            'type' => ['sometimes', 'string', Rule::in([CatalogItem::TYPE_SERVICE, CatalogItem::TYPE_PRODUCT])],
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'price_mode' => ['sometimes', 'string', Rule::in([
                CatalogItem::PRICE_FIXED,
                CatalogItem::PRICE_FROM,
                CatalogItem::PRICE_ON_REQUEST,
            ])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'cta_type' => ['sometimes', 'string', Rule::in([
                CatalogItem::CTA_ENQUIRE,
                CatalogItem::CTA_REQUEST_MEETING,
                CatalogItem::CTA_CALL,
            ])],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'images' => ['sometimes', 'array', 'max:' . $maxFiles],
            'images.*' => ['image', 'mimes:jpeg,png,jpg,gif,webp', 'max:' . $maxKb],
        ]);
    }
}
