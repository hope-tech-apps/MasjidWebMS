<?php

namespace Database\Seeders;

use App\Enums\HadithStrength;
use App\Models\Announcement;
use App\Models\Azkar;
use App\Models\AzkarCategory;
use App\Models\ContactUsReason;
use App\Models\Event;
use App\Models\Hadith;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MasjidMobileAppFeature;
use App\Models\MobileAppFeature;
use App\Models\MobileAppUser;
use App\Models\Service;
use App\Models\Tasbih;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class MasjidDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $masjid = Masjid::create([
            'user_id' => User::where('type', 'MasjidAdmin')->first()->id,
            'name' => 'Al Fatih',
            'email' => 'alfatih@mosque.com',
            'phone' => '+2012345678',
            'country_id' => '54',
            'city_id' => '1578',
            'address' => 'street address, Alex, EG.',
            'latitude' => 35.78056,
            'longitude' => -78.6389
        ]);

        // Masjid logo save
        // $masjid->addMedia(storage_path('app/public/images/mosque-icon.jpg'))
        //     ->preservingOriginal()
        //     ->toMediaCollection('logos');

        // Masjid gallery seed
        // $masjid->addMedia(storage_path('app/public/images/mosque-icon.jpg'))
        //     ->preservingOriginal()
        //     ->toMediaCollection('galleries');
//        $masjid->addMedia(storage_path('app/public/images/shiekh.jpeg'))
//            ->preservingOriginal()
//            ->toMediaCollection('galleries');
//        $masjid->addMedia(storage_path('app/public/images/quruan-book.jpeg'))
//            ->preservingOriginal()
//            ->toMediaCollection('galleries');
//        $masjid->addMedia(storage_path('app/public/images/masjid_on_zokhrufa.png'))
//            ->preservingOriginal()
//            ->toMediaCollection('galleries');
//        $masjid->addMedia(storage_path('app/public/images/inside-mosque.png'))
//            ->preservingOriginal()
//            ->toMediaCollection('galleries');

        $masjid->donationLink()->create([
            'masjid_id' => $masjid->id,
            'link' => 'https://www.website.com/donate',
            'title' => 'Donate Now',
            'message' => 'Donate to support the mosque'
        ]);

//        $masjid->donationLink->addMedia(storage_path('app/public/images/shiekh.jpeg'))
//            ->preservingOriginal()
//            ->toMediaCollection('donationLink');

        $masjid->masjidAbout()->create([
            'masjid_id' => $masjid->id,
            'about' => 'Here is the text about our masjid ... ',
            'mission' => 'Our mission as a masjid is ... ',
            'vision' => 'We have our vission which is ... '
        ]);

//        $masjid->masjidAbout->addMedia(storage_path('app/public/images/al_fatih_logo.png'))
//            ->preservingOriginal()
//            ->toMediaCollection('aboutImages');
//
//        $masjid->masjidAbout->addMedia(storage_path('app/public/images/charity_icon.png'))
//            ->preservingOriginal()
//            ->toMediaCollection('missionIcons');
//
//        $masjid->masjidAbout->addMedia(storage_path('app/public/images/charity_icon.png'))
//            ->preservingOriginal()
//            ->toMediaCollection('visionIcons');

        IqamaTimeSetting::create([
            'masjid_id' => $masjid->id,
            'fajr' => 20,
            'dhuhr' => 10,
            'asr' => 10,
            'maghrib' => 10,
            'isha' => 15
        ]);

        MobileAppUser::create([
            'device_id' => 'test_device_1',
            'masjid_id' => $masjid->id,
            'user_agent' => 'test'
        ]);

//        Announcement::factory()->count(5)->create();
//        Event::factory()->count(5)->create();
//        Service::factory()->count(5)->create();

        // Seed to link masjid with mobile app features
        $features = MobileAppFeature::all();
        foreach ($features as $feature) {
            MasjidMobileAppFeature::create([
                'masjid_id' => $masjid->id,
                'feature_id' => $feature->id,
                'is_available' => true
            ]);
        }
    }
}
