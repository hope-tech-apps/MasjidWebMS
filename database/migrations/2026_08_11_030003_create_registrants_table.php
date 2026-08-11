<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * registrants — WHO a registration is for: the roster rows under one
 * registration (docs/t006-registration-billing-design.md, T-006a). One
 * guardian registration may carry several children; each child is one row.
 *
 * Contacts-first, per .claude/rules/groups.md: a registrant references an
 * existing CRM Contact and never duplicates a person — no name/email columns
 * here, ever. On confirmation T-006b materialises these rows into
 * group_memberships (offerings.group_id) plus guardian_of_contact_id edges via
 * the existing groups invariants; this table stays the registration-side record
 * of who was signed up.
 *
 * Tenant-scoped by a denormalised masjid_id so BelongsToMasjid scopes reads
 * without joining through registrations (same arrangement as group_memberships).
 *
 * Conservative choices where the design doc left detail open:
 *  - unique (registration_id, contact_id): the same person cannot appear twice
 *    under one registration. No column in the pair is nullable, so the index
 *    dedupes exactly (contrast the group_memberships caveat).
 *  - contact_id cascades: a registrant row is pure structure; if a Contact is
 *    hard-deleted the roster row goes with it while the registration itself
 *    (the financial record, with its own nullable payer contact_id) survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['registration_id', 'contact_id']);

            // "Which offerings is this person registered in" — tenant-filtered.
            $table->index(['masjid_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrants');
    }
};
