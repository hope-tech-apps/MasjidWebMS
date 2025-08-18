<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountriesCitiesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        // Clear existing data
        Country::truncate();
        // City::truncate();
        // Read and parse the countries CSV file
        $countries = array_map('str_getcsv', file(storage_path('app/csv/country.csv')));

        // Insert countries into the database
        foreach ($countries as $country) {
            Country::create([
                'id' => $country[0], // Assuming the country name is in the first column
                'name' => $country[1], // Assuming the country name is in the first column
                'code' => $country[2], // Assuming the country code is in the second column
            ]);
        }

        // Read and parse the cities CSV file
        $cities = array_map('str_getcsv', file(storage_path('app/csv/cities.csv')));

        // Insert cities into the database
        foreach ($cities as $city) {
            if ($city[0] && $city[1]) {
                City::create([
                    'name' => $city[0], // Assuming the city name is in the first column
                    'country_id' => $city[1], // Assuming the country ID is in the second column
                ]);
            }
        }
        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
