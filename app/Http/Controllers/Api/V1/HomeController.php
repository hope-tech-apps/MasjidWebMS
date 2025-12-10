<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Models\Service;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $services = Service::filterByMasjid()->latest()->limit(6)->get();
        $announcements = Announcement::filterByMasjid()->latest()->limit(3)->get();
        return response()->api(200, __('api.success'), [
            'sections' => $this->getSections(),
            'services' => ServiceResource::collection($services),
            'announcements' => AnnouncementResource::collection($announcements)
        ]);
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
