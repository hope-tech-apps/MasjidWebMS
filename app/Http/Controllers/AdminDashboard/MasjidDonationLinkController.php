<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\DonationLink;
use App\Models\Masjid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

    public function save(Request $request, $masjid_id)
    {
        try {
            
            $masjid = Masjid::findOrFail($masjid_id);
            $donationLink = $masjid->donationLink;
            
            $validator = Validator::make($request->all(), [
                'link' => 'required|url'
            ]);

            if($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            if($validator->passes()) {
                if($donationLink) {
                    $donationLink->update(['link' => $request['link']]);
                } else {
                    $donationLink = DonationLink::create([
                        'masjid_id' => $masjid->id,
                        'link' => $request['link'],
                        'title' => 'Donation Link',
                        'message' => 'Donate Now'
                    ]);
                }
                return response()->json([
                    'status' => 'success',
                    'data' => $donationLink
                ], Response::HTTP_OK);
            }

        } catch(\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
