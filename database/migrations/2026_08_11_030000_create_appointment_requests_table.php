<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * appointment_requests — the Community vertical's secure intake (PLAN T-021).
 *
 * The pilot tenant is a free clinic whose current site EMAILS appointment
 * requests (name, DOB, reason for visit) through plaintext Gmail. This table is
 * what replaces that: the request lands in the database, tenant-scoped, with the
 * sensitive fields encrypted at rest and readable only through the permission-
 * gated admin endpoints.
 *
 * ENCRYPTION — `date_of_birth` and `reason` hold health-adjacent PII, so they
 * are `encrypted` casts on App\Models\AppointmentRequest (same mechanism as
 * users.two_factor_secret and the MasjidAppPublishing credentials). Ciphertext
 * is Fernet-style base64 far longer than the plaintext, hence TEXT columns, and
 * it is NOT searchable — deliberately: nothing filters on DOB or reason, and a
 * plaintext index would defeat the point.
 *
 * `status` is a plain string, NOT a DB enum — the allowed set lives in PHP as
 * AppointmentRequest::STATUSES, exactly like Masjid::ORG_TYPES and Group::KINDS:
 * adding a status must never require `ALTER TABLE ... MODIFY` on a live table
 * (see .claude/rules/migrations.md for what that did to the SQLite test run).
 *
 * Tenant-scoped by masjid_id. MySQL has no row-level security, so isolation is
 * enforced in the app layer by App\Models\Concerns\BelongsToMasjid and proved by
 * tests/Feature/AppointmentRequestTenantIsolationTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // Contact identity. Name and phone are how the clinic reaches back;
            // email is optional because not every patient has one.
            $table->string('applicant_name');
            $table->string('phone', 32);
            $table->string('email')->nullable();

            // Encrypted at rest (see the class docblock). TEXT because the
            // ciphertext is much longer than the plaintext it protects.
            $table->text('date_of_birth');
            $table->text('reason');

            // Free text on purpose ("weekday mornings", "after Jumu'ah") — slot
            // -based scheduling is explicitly a later slice, and a structured
            // column now would have to be destroyed to build it.
            $table->string('preferred_window')->nullable();

            $table->string('status', 32)->default('new');
            // Where the request came from ('web' for the public endpoint);
            // lets staff-entered phone requests share the queue later.
            $table->string('source', 32)->default('web');

            // Same operational metadata the public form-submission idiom keeps
            // (form_responses): enough to answer an abuse incident, no more.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1000)->nullable();

            $table->timestamps();

            // The triage list is WHERE masjid_id = ? [AND status = ?] newest
            // first; the show/update path is a scoped id lookup (mirrors groups).
            $table->index(['masjid_id', 'status']);
            $table->index(['masjid_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_requests');
    }
};
