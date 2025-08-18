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
            ['name' => 'Qur’an', 'icon' => storage_path('app/public/icons/alqurann.svg')],
            ['name' => 'Hadith', 'icon' => storage_path('app/public/icons/hadith.svg')],
            ['name' => 'Adhkar', 'icon' => storage_path('app/public/icons/azkar.svg')],
            ['name' => 'Qibla', 'icon' => storage_path('app/public/icons/qibla.svg')],
            ['name' => 'Tasbih', 'icon' => storage_path('app/public/icons/tasbih.svg')],
            ['name' => 'Donate', 'icon' => storage_path('app/public/icons/donate.svg')],
            ['name' => 'About Us', 'icon' => storage_path('app/public/icons/about_us.svg')],
            ['name' => 'Gallery', 'icon' => storage_path('app/public/icons/gallery.svg')],
            ['name' => 'Services', 'icon' => storage_path('app/public/icons/services.svg')],
            ['name' => 'Announcements', 'icon' => storage_path('app/public/icons/announcements.svg')],
            ['name' => 'Contact Us', 'icon' => storage_path('app/public/icons/contact.svg')]
        ];

        foreach ($features as $feature) {
            $featureFromDB = MobileAppFeature::create([
                'name' => $feature['name'],
            ]);
            $featureFromDB->addMedia($feature['icon'])
                ->preservingOriginal()
                ->toMediaCollection('featuresIcons');
        }
    }
}
