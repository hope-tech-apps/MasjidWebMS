<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Gallery\StoreGalleryImageRequest;
use App\Models\Masjid;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class MasjidGalleryController extends Controller
{
    public function index($masjid_id)
    {
        $masjid = Masjid::with('gallery')->findOrFail($masjid_id);

        // Clean up orphaned media records (records without actual files)
        $this->cleanupOrphanedMedia($masjid);

        $gallery = $masjid->gallery()->paginate(8);
        return response()->json([
            'status' => 'success',
            'data' => $gallery
        ], Response::HTTP_OK);
    }

    public function store(StoreGalleryImageRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            // Support multiple images uploaded at once (key `images[]`) while staying
            // backwards-compatible with a single `image` upload.
            if ($request->hasFile('images')) {
                $masjid->addMultipleMediaFromRequest(['images'])
                    ->each(function ($fileAdder) {
                        $fileAdder->toMediaCollection('galleries');
                    });
            } elseif ($request->hasFile('image')) {
                $masjid->addMediaFromRequest('image')->toMediaCollection('galleries');
            }

            MobileCache::flushGallery((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => $masjid->gallery
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function delete($masjid_id, $media_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $media = $masjid->gallery()->findOrFail($media_id);
            $media->delete();

            MobileCache::flushGallery((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => $masjid->gallery
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Clean up orphaned media records (records without actual files in storage)
     */
    private function cleanupOrphanedMedia(Masjid $masjid)
    {
        $galleryMedia = $masjid->gallery()->get();

        foreach ($galleryMedia as $media) {
            $filePath = $media->getPath();

            if (!file_exists($filePath)) {
                $media->delete();
            }
        }
    }
}
