<?php

namespace Database\Seeders;

use App\Models\MobileAppFeature;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MobileAppFeaturesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $features = [
            ['name' => 'Qur’an', 'key' => 'quran', 'icon' => storage_path('app/public/icons/alqurann.svg')],
            ['name' => 'Hadith', 'key' => 'hadith', 'icon' => storage_path('app/public/icons/hadith.svg')],
            ['name' => 'Adhkar', 'key' => 'adhkar', 'icon' => storage_path('app/public/icons/azkar.svg')],
            ['name' => 'Qibla', 'key' => 'qibla', 'icon' => storage_path('app/public/icons/qibla.svg')],
            ['name' => 'Tasbih', 'key' => 'tasbih', 'icon' => storage_path('app/public/icons/tasbih.svg')],
            ['name' => 'Donate', 'key' => 'donate', 'icon' => storage_path('app/public/icons/donate.svg')],
            ['name' => 'About Us', 'key' => 'about_us', 'icon' => storage_path('app/public/icons/about_us.svg')],
            ['name' => 'Gallery', 'key' => 'gallery', 'icon' => storage_path('app/public/icons/gallery.svg')],
            ['name' => 'Services', 'key' => 'services', 'icon' => storage_path('app/public/icons/services.svg')],
            ['name' => 'Announcements', 'key' => 'announcements', 'icon' => storage_path('app/public/icons/announcements.svg')],
            ['name' => 'Contact Us', 'key' => 'contact_us', 'icon' => storage_path('app/public/icons/contact.svg')]
        ];

        foreach ($features as $feature) {
            $featureFromDB = MobileAppFeature::create([
                'name' => $feature['name'],
                'key' => $feature['key'],
            ]);
            $featureFromDB->addMedia($feature['icon'])
                ->preservingOriginal()
                ->toMediaCollection('featuresIcons');
        }
    }
}
