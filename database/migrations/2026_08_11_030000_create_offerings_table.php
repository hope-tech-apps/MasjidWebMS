<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * offerings — every registerable thing an organization runs: an event, a program
 * (weekend school semester), an admission, a membership, a clinic appointment.
 * One table for all of them; `kind` is presentation and verticals stay
 * configuration (docs/t006-registration-billing-design.md, T-006a).
 *
 * Tenant-scoped by masjid_id via App\Models\Concerns\BelongsToMasjid; MySQL has
 * no row-level security, so the app-layer scope plus
 * tests/Feature/OfferingTenantIsolationTest are the isolation guarantee.
 *
 * `kind` is a plain string, NOT a DB enum — Offering::KINDS is the authority,
 * validated at the request boundary. Adding a kind (the design already reserves
 * `appointment` for future offering_slots) must never require
 * `ALTER TABLE ... MODIFY` on a live table (.claude/rules/migrations.md).
 *
 * Capacity under concurrency: `registration_count` is DENORMALISED, forms-style
 * (mirrors forms.response_count). T-006b's registration transaction takes
 * `lockForUpdate` on this row, re-checks registration_count < capacity,
 * increments, commits — seats are reserved at `pending`, released by
 * `checkout.session.expired` / the T-006f reaper. Null capacity = unlimited,
 * exactly like forms.capacity.
 *
 * Conservative choices where the design doc left detail open:
 *  - intake_form_id is NOT NULL: the doc marks only group_id nullable, and the
 *    register transaction always validates through FormSchema — an offering
 *    without an intake form cannot take a registration. FK is RESTRICT (no
 *    cascade): forms soft-delete, and a hard form delete must never silently
 *    take its offerings with it.
 *  - group_id (the roster target) nulls on group hard-delete rather than
 *    deleting the offering — losing a roster target must not lose the offering
 *    or its payment history.
 *  - slug gets the same case-sensitive utf8mb4_bin collation as forms/pages:
 *    offerings are public-URL addressed (GET offerings/{slug}) just like forms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 32)->default('event');
            $table->string('name');

            // Public, URL-safe handle; unique PER MASJID (index below).
            $slug = $table->string('slug');
            if (DB::getDriverName() === 'mysql') {
                $slug->collation('utf8mb4_bin');
            }

            // The only intake machinery — answers validate through FormSchema
            // and persist as a normal form_response, never duplicated here.
            $table->foreignId('intake_form_id')->constrained('forms');

            // Where confirmed registrants are materialised as group_memberships.
            // Nullable: an offering may collect money without building a roster.
            $table->foreignId('group_id')->nullable()
                ->constrained('groups')->nullOnDelete();

            // Null capacity means unlimited (forms convention).
            $table->unsignedInteger('capacity')->nullable();

            // Denormalised seat counter — maintained ONLY inside T-006b's locked
            // registration transaction and the seat-release paths, never by mass
            // assignment (guarded on the model, mirrors forms.response_count).
            $table->unsignedInteger('registration_count')->default(0);

            // Registration window; null on either side = unbounded on that side.
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();

            $table->boolean('is_active')->default(true);

            // Presentation/behaviour knobs (confirmation copy, waitlist toggle,
            // …) — same role as forms.settings.
            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Per-tenant slug uniqueness (.claude/rules/migrations.md). Spans
            // soft-deleted rows on purpose: a deleted offering keeps its slug
            // because its registrations/payments are retained.
            $table->unique(['masjid_id', 'slug']);

            $table->index(['masjid_id', 'id']);
            $table->index(['masjid_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offerings');
    }
};
