<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->unsignedBigInteger('list_unit_price_minor')->nullable();
            $table->unsignedBigInteger('staff_unit_price_minor')->nullable();
            $table->string('staff_holder_name', 120)->nullable();
            $table->string('staff_payment_method', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropColumn(['list_unit_price_minor', 'staff_unit_price_minor', 'staff_holder_name', 'staff_payment_method']);
        });
    }
};
