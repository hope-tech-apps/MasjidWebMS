<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contact_credentials — a licensed volunteer's credential on their Contact
 * record: a medical license, a background check, a BLS card (T-023, Community
 * vertical; pilot is a free clinic run on volunteer providers).
 *
 * Credentials hang off the existing Contact (person) model on purpose — the
 * same reasoning as group_memberships: people exist ONCE, as Contacts, and
 * everything else references them. No parallel "provider" table.
 *
 * Tenant-scoped by masjid_id (denormalised alongside contact_id so credential
 * queries — "everything expiring this month" — carry the tenant predicate
 * without joining through contacts). MySQL has no row-level security, so that
 * predicate plus BelongsToMasjid is the whole isolation guarantee, proved by
 * tests/Feature/ContactCredentialTenantIsolationTest.
 *
 * `kind` is a plain string, NOT an enum — the same reasoning as `groups.kind`
 * and `masjids.org_type`: adding a credential kind (every clinic tracks a
 * slightly different set) must never mean `ALTER TABLE … MODIFY` on a live
 * table, which .claude/rules/migrations.md records aborting the SQLite test run
 * for three days. The allowed set lives in PHP as ContactCredential::KINDS and
 * is validated at the request boundary; `label` carries the free text for
 * kind = other.
 *
 * `identifier` (the license number) is a TEXT column because it holds a
 * Laravel `encrypted` cast payload, which is far longer than the plaintext —
 * a license number is sensitive enough that a DB dump must not read it.
 *
 * Status (valid / expiring / expired) is DERIVED from expires_at at read time
 * (accessor + scopes on the model), never stored — a stored status is stale
 * the moment midnight passes, and nothing would be responsible for updating it.
 *
 * The optional document (the scanned license) follows
 * .claude/rules/private-uploads.md: bytes on the private disk under a random
 * name, and these columns are only a pointer. They are all nullable together —
 * a credential without a scan is normal. The bytes are removed in the MODEL
 * layer (ContactCredential's deleting hook), never by the DB cascade below,
 * which fires no model events and would orphan them forever.
 *
 * Blueprint only — nothing here needs raw SQL, so nothing here needs a driver
 * guard (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_credentials', function (Blueprint $table) {
            $table->id();

            // Denormalised tenant key; the cascades are a last-resort backstop
            // only (see the model-layer deletion note in the docblock).
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 64);
            // Free-text display name; REQUIRED at the boundary when kind=other,
            // optional otherwise ("NC Medical Board License" reads better than
            // a constant).
            $table->string('label')->nullable();
            $table->string('issuing_body')->nullable();
            // Encrypted-cast license/certificate number (see docblock).
            $table->text('identifier')->nullable();

            $table->date('issued_at')->nullable();
            // NULL = non-expiring (a background check a state treats as
            // one-time, a lifetime certification).
            $table->date('expires_at')->nullable();

            $table->text('notes')->nullable();

            // Pointer to the optional private document; nullable as a set.
            $table->string('document_original_name')->nullable();
            $table->string('document_mime_type', 191)->nullable();
            $table->unsignedBigInteger('document_size_bytes')->nullable();
            // Stored per-row rather than read from config at download time, so
            // moving the config later cannot orphan what is already here —
            // mirrors form_response_attachments.
            $table->string('document_disk', 32)->nullable();
            $table->string('document_path')->nullable();

            $table->timestamps();

            // The per-contact listing.
            $table->index(['masjid_id', 'contact_id']);
            // The expiring-soon report: this tenant's credentials by expiry.
            $table->index(['masjid_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_credentials');
    }
};
