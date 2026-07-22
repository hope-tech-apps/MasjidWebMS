<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A donor's card last-4 digits, kept for historical lookup.
 *
 * The masjid's bookkeeping identifies people by card last-4 (a person may have
 * several). Storing them lets an admin search "who is card 8016?" and is how an
 * anonymous card row ("Unknown Name Credit 3256") gets attached to a member: the
 * last-4 moves onto that member's contact.
 *
 * ONLY last-4 (+ optional brand). Never a full PAN — the DB never holds one, and
 * a stored 4-digit suffix is not PCI-restricted cardholder data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('last4', 4);
            $table->string('brand', 20)->nullable();   // visa/mc/… if ever known
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['masjid_id', 'last4']);       // "who is card 8016?"
            $table->unique(['contact_id', 'last4']);     // no dup card on one contact
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_cards');
    }
};
