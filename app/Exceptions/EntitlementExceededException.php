<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntitlementExceededException extends Exception
{
    public function __construct(
        public readonly string $limitKey,
        public readonly int $limit,
        public readonly int $currentUsage,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Your current plan does not allow this action ({$limitKey}).");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'limit_key' => $this->limitKey,
            'limit' => $this->limit,
            'current_usage' => $this->currentUsage,
            'upgrade' => [
                'label' => 'View plans',
                'url' => '/dashboard/plan',
            ],
        ], 422);
    }
}
