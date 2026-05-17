<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DonationLink\SaveDonationLinkRequest;
use App\Models\DonationLink;
use App\Models\Masjid;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class MasjidDonationLinkController extends Controller
{
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $donationLink = $masjid->donationLink;
        return response()->json([
            'status' => 'success',
            'data' => $donationLink
        ], Response::HTTP_OK);
    }

    public function save(SaveDonationLinkRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $donationLink = $masjid->donationLink;
            $link = $request->validated()['link'];

            if ($donationLink) {
                $donationLink->update(['link' => $link]);
            } else {
                $donationLink = DonationLink::create([
                    'masjid_id' => $masjid->id,
                    'link' => $link,
                    'title' => 'Donation Link',
                    'message' => 'Donate Now',
                ]);
            }

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::DONATION_LINK);
            MobileCache::flushMasjid((int) $masjid_id, MobileCache::SHOW);

            return response()->json([
                'status' => 'success',
                'data' => $donationLink
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
