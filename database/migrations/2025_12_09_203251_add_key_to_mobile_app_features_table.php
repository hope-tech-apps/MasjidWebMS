<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mobile_app_features', function (Blueprint $table) {
            $key = $table->string('key')->nullable()->after('name');
            // MySQL-only collation; guard for sqlite portability (test suite).
            if (DB::getDriverName() === 'mysql') {
                $key->collation('utf8mb4_bin');
            }
        });

        // Populate the key field based on the name field
        $features = [
            'Qur\'an' => 'quran',
            'Hadith' => 'hadith',
            'Adhkar' => 'adhkar',
            'Qibla' => 'qibla',
            'Tasbih' => 'tasbih',
            'Donate' => 'donate',
            'About Us' => 'about_us',
            'Gallery' => 'gallery',
            'Services' => 'services',
            'Announcements' => 'announcements',
            'Contact Us' => 'contact_us'
        ];

        foreach ($features as $name => $key) {
            DB::table('mobile_app_features')
                ->where('name', $name)
                ->update(['key' => $key]);
        }

        // For any features that don't have a key yet, generate one from the name
        $featuresWithoutKey = DB::table('mobile_app_features')
            ->whereNull('key')
            ->orWhere('key', '')
            ->get();

        foreach ($featuresWithoutKey as $feature) {
            $key = strtolower(str_replace(' ', '_', $feature->name));
            DB::table('mobile_app_features')
                ->where('id', $feature->id)
                ->update(['key' => $key]);
        }

        // Make the key field unique and not nullable
        Schema::table('mobile_app_features', function (Blueprint $table) {
            $table->string('key')->unique()->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mobile_app_features', function (Blueprint $table) {
            $table->dropColumn('key');
        });
    }
};
