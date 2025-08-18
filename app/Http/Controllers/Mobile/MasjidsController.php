<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;

class MasjidsController extends Controller
{
    public function index()
    {
        $masjids = Masjid::with('logo')->get();
        return response()->json([
            'status' => 'success',
            'data' => $masjids
        ]);
    }

    public function show($masjid_id)
    {
        $masjid = Masjid::with(
            'logo',
            'donationLink.image',
            'masjidAbout.aboutImage',
            'masjidAbout.missionIcon',
            'masjidAbout.visionIcon',
            'socialMediaLinks'
        )->findOrFail($masjid_id);
        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ]);
    }

    public function gallery($masjid_id)
    {
        $masjid = Masjid::with('gallery')->findOrFail($masjid_id);
        $gallery = $masjid->gallery->toArray();
        return response()->json([
            'status' => 'success',
            'data' => $gallery
        ]);
    }

    public function about($masjid_id)
    {
        $masjid = Masjid::with('masjidAbout.aboutImage', 'masjidAbout.missionIcon', 'masjidAbout.visionIcon')->findOrFail($masjid_id);
        $about = $masjid->masjidAbout;
        return response()->json([
            'status' => 'success',
            'data' => $about
        ]);
    }

    public function donationLink($masjid_id)
    {
        $masjid = Masjid::with('donationLink.image')->findOrFail($masjid_id);
        $donationLink = $masjid->donationLink;
        return response()->json([
            'status' => 'success',
            'data' => $donationLink
        ]);
    }
}
