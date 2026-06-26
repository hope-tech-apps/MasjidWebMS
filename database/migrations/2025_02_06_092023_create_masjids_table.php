<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('masjids', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $name = $table->string('name')->unique();
            // utf8mb4_bin is a MySQL-only collation; guard it so the schema
            // stays portable under sqlite (used by the in-memory test suite),
            // which has no such collation sequence.
            if (DB::getDriverName() === 'mysql') {
                $name->collation('utf8mb4_bin');
            }

            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone')->unique();
            $table->timestamp('phone_verified_at')->nullable();
            
            $table->string('country_id');
            $table->string('city_id');
            $table->string('address');

            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);

            $table->string('website_link')->unique()->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users', 'id')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id')->onDelete('set null');
            $table->foreignId('deleted_by')->nullable()->constrained('users', 'id')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('masjids');
    }
};
