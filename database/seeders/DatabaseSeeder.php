<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        // $this->call(CountriesCitiesSeeder::class);

        DB::statement("SET FOREIGN_KEY_CHECKS=0;");
        DB::table('masjid_mobile_app_features')->truncate();
        DB::table('announcements')->truncate();
        DB::table('events')->truncate();
        DB::table('services')->truncate();
        DB::table('tasabih')->truncate();
        DB::table('azkar')->truncate();
        DB::table('azkar_categories')->truncate();
        DB::table('hadiths')->truncate();
        DB::table('contact_us_reasons')->truncate();
        DB::table('mobile_app_users')->truncate();
        DB::table('masjid_abouts')->truncate();
        DB::table('iqama_time_settings')->truncate();
        DB::table('mobile_app_features')->truncate();
        DB::table('masjids')->truncate();
        DB::table('users')->truncate();
        DB::statement("SET FOREIGN_KEY_CHECKS=1;");

        $this->call(MobileAppFeaturesSeeder::class);
        $this->call(UsersSeeder::class);
        $this->call(MasjidDataSeeder::class);
        $this->call(AppLevelDataSeeder::class);
    
    }
}
