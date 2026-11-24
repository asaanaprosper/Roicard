<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Services\CatalogEnquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogEnquiryController extends Controller
{
    public function __construct(protected CatalogEnquiryService $enquiries) {}

    public function store(Request $request, CatalogItem $item): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:3000'],
        ]);

        $enquiry = $this->enquiries->submit(
            $item,
            $validated,
            $request->user('sanctum')
        );

        return response()->json([
            'enquiry' => [
                'id' => $enquiry->id,
                'catalog_item_id' => $enquiry->catalog_item_id,
                'status' => $enquiry->status,
            ],
            'message' => 'Thanks — your enquiry has been sent to the business.',
        ], 201);
    }
}
