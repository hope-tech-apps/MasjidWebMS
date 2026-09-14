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

            // A switched-off module's records are not offered to the
            // organisation's own admins: the result would link to a screen
            // their menu hides and their API refuses. The keys stay, empty, so
            // the header search reads the same shape either way. A SuperAdmin
            // still finds them (the sidebar lists the screen under "Switched
            // off"). moduleIsOff is fail-open, so a stale config hides nothing.
            $hidesSwitchedOff = $request->user()?->type !== 'SuperAdmin';

            $results['masjidAbout'] = $hidesSwitchedOff && $masjid->moduleIsOff('about_us')
                ? collect()
                : $masjid->masjidAbout()->searchLike($inputs['search_for'])->get();
            $results['socialMediaLinks'] = $masjid->socialMediaLinks()->searchLike($inputs['search_for'])->get();
            $results['announcements'] = $hidesSwitchedOff && $masjid->moduleIsOff('announcements')
                ? collect()
                : $masjid->announcements()->searchLike($inputs['search_for'])->get();
            // The services admin index stays open while Services is off (other
            // screens pick from it), but a search result links to the Services
            // screen itself, which is hidden and refuses.
            $results['services'] = $hidesSwitchedOff && $masjid->moduleIsOff('services')
                ? collect()
                : $masjid->services()->searchLike($inputs['search_for'])->get();

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
                'data' => \App\Support\Errors::publicMessage($e)
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
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);

        }
    }
}
