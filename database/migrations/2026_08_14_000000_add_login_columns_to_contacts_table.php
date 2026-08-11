<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-015c — the four columns that let a `contacts` row hold a parent/guardian
 * login. See docs/t015-parent-identity-design.md §2 and §3.
 *
 * STRICTLY ADDITIVE, and observably so: every column is nullable with no
 * default, so every existing contact reads as "no login" and the roster import,
 * the CRM create/update endpoints and the merge flow all keep working with no
 * change at all. Nothing in this migration is required for a contact to exist.
 *
 * ---------------------------------------------------------------------------
 * Why `login_email` is its own column and not `contacts.email`
 * ---------------------------------------------------------------------------
 *
 * `contacts.email` is a CRM attribute: it is imported in bulk from a roster
 * spreadsheet, it is frequently a HOUSEHOLD address shared by both parents, and
 * `GroupAudience::identitiesFor()` already reads it as an identity bridge for
 * staff. A credential that opens a child's photographs and behaviour records
 * must not be conjured out of a column with those properties:
 *
 *  - a shared household address would make one credential open both parents'
 *    views, and a custody order is then unexecutable — you cannot revoke one
 *    parent without revoking the other. Per-CONTACT login_email is what makes
 *    the design's answer ("execute custody by deleting the guardian edge")
 *    actually available (§3).
 *  - an imported address was never verified by anyone. `login_email` is set
 *    deliberately by an administrator, one contact at a time, and is the ONLY
 *    address an invite is ever delivered to (T-015d).
 *
 * These columns are deliberately NOT in `Contact::$fillable` — see the model.
 *
 * ---------------------------------------------------------------------------
 * `unique (masjid_id, login_email)` — per tenant, NEVER global
 * ---------------------------------------------------------------------------
 *
 * A globally unique credential address would be a cross-tenant correlation and
 * enumeration channel: "is this parent also at that other school?" is exactly
 * the question this product must not be able to answer, and it is the reason
 * the design rejected a shared portal-user identity table outright. Scoping
 * uniqueness to the masjid keeps two tenants' families provably unrelatable,
 * at the cost of one human holding one login per organisation — which the
 * design accepts explicitly (§2, §11).
 *
 * NULLs are distinct in a unique index on both drivers, so the overwhelming
 * majority of contacts — the ones with no login at all — are unconstrained.
 * This is why a plain unique index is correct here and the partial-index /
 * generated-column dance of `masjid_user.default_key` is not needed
 * (.claude/rules/migrations.md).
 *
 * A SOFT-DELETED contact keeps pinning its `login_email`, deliberately. Freeing
 * the address on delete would let a new contact silently inherit a mailbox that
 * used to open a specific child's records; re-issuing it is an operator's
 * decision (restore the contact, or clear the column), not a side effect of the
 * index predicate.
 *
 * Blueprint only — no raw SQL, so there is nothing to driver-guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // The address an invite is delivered to, and the identity a parent
            // types. Nullable: a contact with no login is the normal case.
            $table->string('login_email')->nullable()->after('email');

            // Set == this contact may authenticate. Never set by an import.
            $table->timestamp('login_enabled_at')->nullable()->after('login_email');

            // The hard cut. Checked by the `family.active` middleware on EVERY
            // family request, not only at mint time, so a token already in a
            // phone's keychain stops working on the next request rather than
            // when it happens to expire. See App\Http\Middleware\EnsureFamilyLoginActive.
            $table->timestamp('login_revoked_at')->nullable()->after('login_enabled_at');

            // Operator visibility only — nothing reads it and nothing
            // authorizes on it. Written by the login flow that mints the token
            // (T-015d); it ships here because the four columns are one additive
            // ALTER on a live table and splitting them across two deploys buys
            // nothing.
            $table->timestamp('last_login_at')->nullable()->after('login_revoked_at');

            $table->unique(['masjid_id', 'login_email'], 'contacts_masjid_login_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // The index first: it is built on one of the columns below.
            $table->dropUnique('contacts_masjid_login_email_unique');

            $table->dropColumn([
                'login_email',
                'login_enabled_at',
                'login_revoked_at',
                'last_login_at',
            ]);
        });
    }
};
