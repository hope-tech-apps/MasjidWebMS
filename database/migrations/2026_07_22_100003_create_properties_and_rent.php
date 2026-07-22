<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property & rent tracking — a SEPARATE component from the donor CRM.
 *
 * The masjid owns rental properties and tracks rent in the same spreadsheet as
 * donations, but rent is NOT a charitable gift and is never receiptable. It gets
 * its own tables so it never mixes into donor totals or year-end statements.
 *
 *   - properties     one row per owned property (name, address, tenant, rent)
 *   - rent_payments  a dated rent payment (or negative adjustment for a vacancy)
 *
 * Tenant-scoped by masjid_id like everything else. Amounts in integer cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->string('name');                      // "Brick House 2"
            $table->string('address')->nullable();       // "1910 S Mebane St"
            $table->string('tenant_name')->nullable();
            $table->unsignedBigInteger('monthly_rent')->nullable();  // expected rent, cents
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('import_batch')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['masjid_id', 'is_active']);
        });

        Schema::create('rent_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('paid_on');
            $table->bigInteger('amount');                 // cents; signed (vacancy adj. can be negative)
            $table->string('payment_method', 30)->nullable();
            $table->string('note')->nullable();
            $table->string('import_batch')->nullable();
            $table->timestamps();

            $table->index(['masjid_id', 'property_id']);
            $table->index('import_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_payments');
        Schema::dropIfExists('properties');
    }
};
