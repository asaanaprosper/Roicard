<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Services\ProfileVisibilityService;
use App\Support\BearerViewer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorized CV/document download.
 *
 * The CV collection is stored on the private local disk. Media marked visible
 * may be downloaded from a visible public profile; hidden CVs are available
 * only to the owner or an administrator.
 */
class CvDownloadController extends Controller
{
    public function __construct(protected ProfileVisibilityService $visibility) {}

    public function download(Request $request, Profile $profile, Media $media): BinaryFileResponse|StreamedResponse
    {
        abort_unless(
            $media->model_type === Profile::class
            && (int) $media->model_id === (int) $profile->id
            && $media->collection_name === 'cv',
            404
        );

        $viewer = $this->viewer($request);
        $isOwner = $viewer && (int) $viewer->getKey() === (int) $profile->user_id;
        $isAdmin = $viewer && $viewer->isAdmin();

        $isVisible = (bool) $media->getCustomProperty('visible', false)
            && $profile->is_live
            && $profile->is_published
            && $this->visibility->canView($viewer, $profile);

        if (! $isOwner && ! $isAdmin && ! $isVisible) {
            abort(403, 'This document is private.');
        }

        $disk = $media->disk ?: config('media-library.disk_name');
        abort_unless(Storage::disk($disk)->exists($media->getPathRelativeToRoot()), 404);

        $response = Storage::disk($disk)->download(
            $media->getPathRelativeToRoot(),
            $media->file_name,
            [
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );

        return $response;
    }

    protected function viewer(Request $request): ?\App\Models\User
    {
        if ($request->bearerToken()) {
            return BearerViewer::resolve($request);
        }

        return $request->user('sanctum');
    }
}
