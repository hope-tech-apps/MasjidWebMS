<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `contacts.sms_consent_evidence` becomes TEXT.
 *
 * It was varchar(255), sized for the short note the column was introduced for
 * ("signed paper form at the Eid dinner"). Storing the DISCLOSURE ITSELF — the
 * sentence the subscriber actually agreed to, which is the only version of this
 * column that is evidence rather than a description of evidence — does not fit:
 * the Jummah-lunch disclosure is 297 characters, and any wording that carries
 * the required identity / frequency / not-a-condition-of-purchase / rates /
 * STOP disclosures will be about that long.
 *
 * MySQL in strict mode rejected the write; SQLite, which the test suite runs on,
 * does not enforce varchar lengths at all, so a green suite sat on top of a
 * column that could not hold the string in production. Widening is the fix
 * rather than trimming the wording, because the constraint here belongs to the
 * regulation, not to the schema.
 *
 * TEXT holds everything varchar(255) held, so this is backwards compatible and
 * needs no data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->text('sms_consent_evidence')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('sms_consent_evidence', 255)->nullable()->change();
        });
    }
};
