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
        $donationLink = $masjid->donationLink?->load('image');
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

            // Only persist the text fields the admin actually sent. Fall back to the
            // legacy defaults on first create so existing clients keep a sane label.
            $inputs = $request->safe()->only(['link', 'title', 'message']);

            if ($donationLink) {
                $donationLink->update($inputs);
            } else {
                $donationLink = DonationLink::create(array_merge([
                    'masjid_id' => $masjid->id,
                    'title' => 'Donation Link',
                    'message' => 'Donate Now',
                ], $inputs));
            }

            if ($request->hasFile('image')) {
                $donationLink->clearMediaCollection('donation_link');
                $donationLink->addMediaFromRequest('image')->toMediaCollection('donation_link');
            }

            // Flushes mobile (donation_link, show) AND web V1 (/v1/settings + every
            // cached page — the donation section binds to DonationLink via
            // SectionContentBinder at read time). flushDonation() handles all of it.
            MobileCache::flushDonation((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => $donationLink->load('image')
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
