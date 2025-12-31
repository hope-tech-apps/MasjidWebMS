<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $superAdmin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'test@admin.com',
            'password' => Hash::make('password'),
            'phone' => '+123456789',
            'type' => 'SuperAdmin'
        ]);

//        $superAdmin->addMedia(storage_path('app/public/images/person_avatar_ui_design.jpeg'))
//            ->preservingOriginal()
//            ->toMediaCollection('avatars');

        $masjidAdmin = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@masjid.com',
            'password' => Hash::make('12345678'),
            'phone' => '+123456789',
            'type' => 'MasjidAdmin'
        ]);

        // $masjidAdmin->addMedia(storage_path('app/public/images/mosque-icon.jpg'))
        //     ->preservingOriginal()
        //     ->toMediaCollection('avatars');

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@user.com',
            'password' => Hash::make('12345678'),
            'phone' => '+123456789',
            'type' => 'MasjidAdmin'
        ]);
    }
}
