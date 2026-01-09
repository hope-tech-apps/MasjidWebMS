<?php

namespace Database\Seeders;

use App\Enums\HadithStrength;
use App\Models\Azkar;
use App\Models\AzkarCategory;
use App\Models\ContactUsReason;
use App\Models\Hadith;
use App\Models\Tasbih;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AppLevelDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        ContactUsReason::insert([
            [
                'text' => 'General Inquiry',
                'show_to_users' => true
            ],
            [
                'text' => 'Marriage',
                'show_to_users' => true
            ],
            [
                'text' => 'Funeral',
                'show_to_users' => true
            ],
            [
                'text' => 'Social Services',
                'show_to_users' => true
            ],
            [
                'text' => 'Other',
                'show_to_users' => true
            ]
        ]);

        Hadith::create([
            'title' => 'Title of Hadith',
            'isnad' => 'اسناد الحديث, حدثنا شخص عن شخص عن ...',
            'matn' => 'متن الحديث, نص الحديث بالعربية ...',
            'strength' => HadithStrength::VALUE_FIVE->toJson(),
            'muhaddith' => ['en' => 'Narrated Muhaddith Name in English', 'ar' => 'اسم الحدث بالعربية'],
            'references' => [['title' => 'ref1', 'reference' => 'test ref 1'], ['title' => 'ref2', 'reference' => 'test ref 2']],
            'description' => 'Hadith text or description in English',
            'show_date' => Carbon::now()->format('Y-m-d')
        ]);

        AzkarCategory::create([
            'title' => 'Sabah Adhkar',
            'description' => 'For morning time'
        ]);

        Azkar::create([
            'azkar_category_id' => AzkarCategory::first()->id,
            'title' => ['en' => 'thikr title', 'ar' => 'عنوان الذكر'],
            'text' => ['en' => 'thikr text', 'ar' => 'نص الذكر'],
            'bless' => ['en' => 'thikr bless', 'ar' => 'أجر الذكر'],
            'pronunciation' => 'thi-ker-text',
            'frequency' => 3,
            'reference' => 'thiker reference'
        ]);

        Tasbih::create([
            'text' => [
                'en' => 'I seek forgiveness from Allah',
                'ar' => 'أستغفر الله العظيم'
            ],
            'pronunciation' => 'As-tagh-fir-ul-lah Al-adh-em',
            'reference' => 'This should hold the reference of Thikr, but it is optional.'
        ]);
    }
}
