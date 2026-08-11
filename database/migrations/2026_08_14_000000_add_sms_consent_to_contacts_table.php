<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS consent, recorded on the contact (T-009).
 *
 * .claude/rules/broadcasts.md, prerequisite 4, written when T-008 deliberately
 * omitted SMS: "`contacts` has `phone`, and that is all — there is no opt-in
 * column, no consent timestamp and no source-of-consent field. A phone number
 * captured on a school admissions form is not consent to receive bulk
 * announcements." These are the columns that sentence was waiting for.
 *
 * ## Four columns, because one boolean cannot be defended
 *
 * Under the TCPA the organisation carries the burden of PROVING prior express
 * written consent for a bulk message. "opt_in = 1" proves nothing: it does not
 * say when, it does not say how, and it cannot be told apart from a bulk UPDATE
 * somebody ran during an import. So consent is a RECORD, not a flag:
 *
 *  - `sms_opt_in`          — the explicit affirmative. Default FALSE for every
 *                            existing row, which is the only safe reading of a
 *                            phone number captured before this column existed.
 *  - `sms_consent_at`      — WHEN. Server time, never client-supplied.
 *  - `sms_consent_source`  — HOW, from a CONSTANT SET (Contact::SMS_CONSENT_SOURCES)
 *                            rather than free text. Free text produces forty
 *                            spellings of "website" and cannot be audited or
 *                            queried; a fixed vocabulary can answer "show me
 *                            everyone whose consent came from the admissions
 *                            form" three years later, which is exactly the
 *                            question a demand letter asks. It is a plain
 *                            string column, not a DB enum, so adding a source is
 *                            never `ALTER TABLE … MODIFY` on a live table
 *                            (.claude/rules/migrations.md).
 *  - `sms_consent_evidence`— free text BESIDE the constant, for the specific
 *                            artifact ("web form response #4182", "signed
 *                            2026-03-04 registration packet"). The constant set
 *                            makes it queryable; this makes it provable. Both,
 *                            not either.
 *
 * And the opt-out state:
 *
 *  - `sms_opted_out_at`    — this contact asked to stop. Set by the inbound
 *                            STOP webhook and by an admin recording a verbal
 *                            withdrawal.
 *
 * `Contact::hasSmsConsent()` requires the flag AND the timestamp AND the source
 * AND a null opt-out — so a half-written row (a flag set by a careless import
 * with no provenance) reads as NO consent rather than as permission.
 *
 * ## The opt-out on this row is a MIRROR, not the authority
 *
 * `sms_opted_out_at` dies with the contact: a re-import, a delete-and-re-add or
 * a merge would resurrect a person who said STOP as a clean record. The
 * authority is therefore `sms_suppressions`, which is keyed on the NUMBER and
 * has no foreign key to `contacts` at all — see that migration. This column
 * exists so the directory screen can show the state without a join, and so the
 * audience query can shed most suppressed rows in SQL before the definitive
 * per-number check runs.
 *
 * PURELY ADDITIVE. Every column is nullable or defaulted, nothing that exists
 * today reads them, and a contact with no consent record simply cannot be
 * texted — which is the behaviour that existed before this migration, now
 * enforced rather than implied.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed. (ADD COLUMN is
 * supported on SQLite; the dialect trap in .claude/rules/migrations.md is
 * ALTER … MODIFY, which this is not.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // The affirmative. FALSE for every pre-existing row.
            $table->boolean('sms_opt_in')->default(false)->after('phone');

            // WHEN consent was recorded. NULL means no consent — never
            // "unknown, assume yes".
            $table->timestamp('sms_consent_at')->nullable()->after('sms_opt_in');

            // HOW it was obtained (Contact::SMS_CONSENT_SOURCES).
            $table->string('sms_consent_source', 32)->nullable()->after('sms_consent_at');

            // The specific artifact that proves it.
            $table->string('sms_consent_evidence', 255)->nullable()->after('sms_consent_source');

            // The mirror of the suppression list. `sms_suppressions` is the
            // authority; this is the denormalised copy the directory renders.
            $table->timestamp('sms_opted_out_at')->nullable()->after('sms_consent_evidence');

            // The audience resolver's predicate, tenant-first.
            $table->index(['masjid_id', 'sms_opt_in'], 'contacts_masjid_sms_opt_in_index');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_masjid_sms_opt_in_index');
            $table->dropColumn([
                'sms_opt_in',
                'sms_consent_at',
                'sms_consent_source',
                'sms_consent_evidence',
                'sms_opted_out_at',
            ]);
        });
    }
};
