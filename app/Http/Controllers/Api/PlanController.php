<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlanUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function __construct(protected PlanUsageService $plans) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->plans->snapshot($request->user()));
    }
}
