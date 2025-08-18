<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class MasjidGalleryController extends Controller
{
    public function index($masjid_id)
    {
        $masjid = Masjid::with('gallery')->findOrFail($masjid_id);
        $gallery = $masjid->gallery()->paginate(8);
        return response()->json([
            'status' => 'success',
            'data' => $gallery
        ], Response::HTTP_OK);
    }

    public function store(Request $request, $masjid_id)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048'
            ]);

            if($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            if($validator->passes()) {
                $masjid->addMediaFromRequest('image')->toMediaCollection('galleries');
                return response()->json([
                    'status' => 'success',
                    'data' => $masjid->gallery
                ], Response::HTTP_OK);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function delete($masjid_id, $media_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $media = $masjid->gallery()->findOrFail($media_id);
            $media->delete();
            return response()->json([
                'status' => 'success',
                'data' => $masjid->gallery
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
