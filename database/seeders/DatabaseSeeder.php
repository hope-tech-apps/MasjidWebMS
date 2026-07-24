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
        // GUARD: the block below TRUNCATES ~15 tenant tables (masjids, users, ...).
        // Running this on production would wipe every paying tenant. Refuse to run
        // in production unless explicitly overridden for a deliberate reseed.
        if (app()->environment('production') && ! env('ALLOW_DESTRUCTIVE_SEED')) {
            $this->command->error('Refusing to run the destructive DatabaseSeeder on production. Set ALLOW_DESTRUCTIVE_SEED=true to override.');
            return;
        }

//         $this->call(CountriesCitiesSeeder::class);

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
        $this->call(PagesSeeder::class);

        // Additive Spatie roles/permissions + backfill of the `type`->role bridge.
        // Runs last so every user created above is mirrored to its role. Idempotent.
        $this->call(RolesAndPermissionsSeeder::class);

    }
}
