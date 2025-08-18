<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Azkar;
use App\Models\DonationLink;
use App\Models\Hadith;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MasjidAbout;
use App\Models\MasjidSocialMediaLink;
use App\Models\Service;
use App\Models\Tasbih;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardSearchController extends Controller
{
    // Masjid Data Models
    protected array $MASJID_DM = [
        'announcement' => Announcement::class,
        'service' => Service::class,
        'donation' => DonationLink::class,
        'about' => MasjidAbout::class,
        'socialmedia' => MasjidSocialMediaLink::class
    ];

    // App Level Data Models
    protected array $APP_DM = [
        'azkar' => Azkar::class,
        'hadith' => Hadith::class,
        'tasbih' => Tasbih::class
    ];

    public function searchForMasjidDataRecords(Request $request, $masjid_id)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);
            $inputs = $request->validate(['search_for' => 'nullable|string']);
            $results = [];

            $results['masjidAbout'] = $masjid->masjidAbout()->searchLike($inputs['search_for'])->get();
            $results['socialMediaLinks'] = $masjid->socialMediaLinks()->searchLike($inputs['search_for'])->get();
            $results['announcements'] = $masjid->announcements()->searchLike($inputs['search_for'])->get();
            $results['services'] = $masjid->services()->searchLike($inputs['search_for'])->get();

            foreach($this->APP_DM as $key => $model) {
                $results[$key] = $model::searchLike($inputs['search_for'])->get();
            }

            return response()->json([
                'status' => 'success',
                'data' => $results
            ], Response::HTTP_OK);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'failed',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);

        }
    }

    public function searchForSuperDataRecords(Request $request)
    {
        try {

            $inputs = $request->validate(['search_for' => 'nullable|string']);
            $results = [];

            $results['users'] = User::searchLike($inputs['search_for'])->get();
            $results['masjids'] = Masjid::searchLike($inputs['search_for'])->get();

            return response()->json([
                'status' => 'success',
                'data' => $results
            ], Response::HTTP_OK);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'failed',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);

        }
    }
}
