<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Models\Service;
use App\Support\MobileCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $masjidId = (int) $request->header('masjid-id');

        $payload = Cache::remember(
            MobileCache::masjidKey($masjidId, MobileCache::V1_HOME),
            MobileCache::TTL_MEDIUM,
            fn() => $this->buildPayload()
        );

        return response()->api(200, __('api.success'), $payload);
    }

    /**
     * Assemble the (cacheable) /v1/home payload as plain arrays. Flushed via
     * MobileCache::flushAbout / flushServices / flushAnnouncements / flushPages
     * whenever an admin edits the about block, services, or announcements.
     */
    private function buildPayload(): array
    {
        $services = Service::filterByMasjid()->latest()->limit(6)->get();
        $announcements = Announcement::filterByMasjid()->latest()->limit(3)->get();

        return [
            'sections' => $this->getSections(),
            'services' => ServiceResource::collection($services)->resolve(),
            'announcements' => AnnouncementResource::collection($announcements)->resolve(),
        ];
    }

    public function getSections()
    {
        $masjid = Masjid::with('masjidAbout.aboutImage', 'masjidAbout.missionIcon', 'masjidAbout.visionIcon')
            ->findOrFail(request()->header('masjid-id'));

        $aboutUs = $masjid->masjidAbout;

        return [
            'main' => [
                'title' => 'Main',
                'sub_title' => 'Surah An-Nur, Verse 56',
                'text' => 'And establish prayer and give zakah and obey the Messenger - that you may receive mercy.',
                'image_url' => '/assets/icons/landing-page/bg-mosque.svg'
            ],
            'about_us' => [
                'title' => 'About Us',
                'sub_title' => $aboutUs?->mission ?? 'Our Mission',
                'text' => $aboutUs?->about ?? 'About Us',
                'image_url' => $aboutUs?->aboutImage?->original_url ?? '/assets/icons/landing-page/bg-mosque.svg'
            ],
            'contact_us' => [
                'title' => 'Contact Us',
                'sub_title' => 'Contact Us',
                'text' => 'Contact Us',
                'image_url' => '/assets/icons/landing-page/bg-mosque.svg'
            ],

        ];
    }
}
