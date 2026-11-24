<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AiConsentService;
use App\Services\Ai\AiUsageService;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\ProfileAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiController extends Controller
{
    public function __construct(
        protected ProfileAiService $ai,
        protected AiUsageService $usage,
        protected AiConsentService $consent,
        protected GeminiClient $client,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->statusPayload($request->user()));
    }

    public function consent(Request $request): JsonResponse
    {
        $request->validate([
            'accepted' => ['required', 'accepted'],
        ]);

        $user = $this->consent->accept($request->user());

        return response()->json([
            ...$this->statusPayload($user),
            'message' => 'AI data-processing consent recorded.',
        ]);
    }

    public function requestCatalogDraft(Request $request, Profile $profile): JsonResponse
    {
        $maxChars = max(1000, (int) config('roicard.ai.max_source_text_chars', 12000));

        $validated = $request->validate([
            'source_text' => ['nullable', 'string', 'max:' . $maxChars],
            'website_url' => ['nullable', 'url', 'max:2048'],
        ]);

        return response()->json(
            $this->ai->catalogDraft($request->user(), $profile, $validated)
        );
    }

    public function updateCatalogDraftItem(Request $request, Profile $profile, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', 'string', Rule::in(['service', 'product'])],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
        ]);

        return response()->json(
            $this->ai->updateCatalogDraftItem($request->user(), $profile, $itemId, $validated)
        );
    }

    public function deleteCatalogDraftItem(Request $request, Profile $profile, string $itemId): JsonResponse
    {
        return response()->json(
            $this->ai->deleteCatalogDraftItem($request->user(), $profile, $itemId)
        );
    }

    public function approveCatalogDraftItems(Request $request, Profile $profile): JsonResponse
    {
        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['string', 'max:64'],
        ]);

        return response()->json(
            $this->ai->approveCatalogDraftItems($request->user(), $profile, $validated['item_ids'])
        );
    }

    public function suggestReuse(Request $request, Profile $profile): JsonResponse
    {
        $validated = $request->validate([
            'source_profile_id' => ['required', 'integer', 'exists:profiles,id'],
        ]);

        $source = Profile::query()->findOrFail((int) $validated['source_profile_id']);

        return response()->json(
            $this->ai->suggestProfileReuse($request->user(), $profile, $source)
        );
    }

    public function interpretSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:500'],
        ]);

        return response()->json(
            $this->ai->interpretSearch($request->user(), $validated['q'])
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function statusPayload(User $user): array
    {
        $usage = $this->usage->status($user);
        $configured = $this->client->configured();
        $hasConsent = $this->consent->hasConsent($user);
        $remaining = $usage['remaining_today'];

        $message = null;
        if (! $configured) {
            $message = 'AI drafting is not configured on this deployment. You can continue manually.';
        } elseif (! $hasConsent) {
            $message = 'Accept the AI data-processing notice before using AI features.';
        } elseif ($remaining !== null && $remaining <= 0) {
            $message = 'You have reached your daily AI limit. You can continue manually or try again tomorrow.';
        }

        return array_merge([
            'configured' => $configured,
            'available' => $configured && $hasConsent && ($remaining === null || $remaining > 0),
            'provider' => (string) config('roicard.ai.provider', 'gemini'),
            'model' => $this->client->model(),
            'provider_region' => $this->client->region(),
            'consent_required' => ! $hasConsent,
            'consent_version' => $this->consent->version(),
            'consent_text' => $this->consent->text(),
            'consented_at' => $user->ai_consent_at?->toISOString(),
            'message' => $message,
        ], $usage);
    }
}
