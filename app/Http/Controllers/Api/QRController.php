<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RecordAnalyticsJob;
use App\Models\Profile;
use App\Services\ProfileVisibilityService;
use App\Services\QRService;
use App\Support\BearerViewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class QRController extends Controller
{
    public function __construct(
        protected QRService $qrService,
        protected ProfileVisibilityService $visibility,
    ) {}

    /**
     * Scan entry point. The QR code encodes this URL; hitting it records a
     * real qr_scan event then redirects the visitor to the public profile.
     */
    public function show(string $slug, Request $request): RedirectResponse
    {
        $profile = Profile::where('slug', $slug)->firstOrFail();
        $viewer = $request->user() ?? BearerViewer::resolve($request);

        if (! $profile->is_live || ! $profile->is_published || ! $this->visibility->canView($viewer, $profile)) {
            abort(403, 'This profile is not available to you.');
        }

        // Record an attributable scan event. Signature cards must be
        // distinguishable from an ordinary QR scan, and only carry referral
        // metadata when the member enabled it on their signature card.
        $source = $request->string('source')->toString();
        $type = $source === 'signature' ? 'signature_scan' : 'qr_scan';
        $metadata = array_filter([
            'source' => $source ?: 'qr',
            'referral_code' => $request->string('ref')->toString() ?: null,
            'profile_id' => $profile->id,
        ], fn ($value) => $value !== null && $value !== '');

        RecordAnalyticsJob::dispatch($profile->user_id, $type, $metadata);

        return redirect($this->profileUrl($slug) . ($source === 'signature' ? '?src=signature' : ''))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Serves the QR SVG for display/download in the app. The QR encodes the
     * scan endpoint (not the profile URL directly) so scans are attributable.
     */
    public function image(string $slug): Response
    {
        $profile = Profile::where('slug', $slug)->firstOrFail();

        $qrSvg = $this->qrService->generate($this->scanUrl($slug));

        return response($qrSvg, 200)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    protected function profileUrl(string $slug): string
    {
        return config('app.frontend_url', config('app.url')) . '/' . $slug;
    }

    protected function scanUrl(string $slug): string
    {
        return config('app.frontend_url', config('app.url')) . '/api/qr/' . $slug;
    }
}
