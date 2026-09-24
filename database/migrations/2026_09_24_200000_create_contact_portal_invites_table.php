<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 7-day link that puts a parent INSIDE the portal their office just opened.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS TABLE EXISTS AT ALL
 * ---------------------------------------------------------------------------
 *
 * `App\Services\Family\FamilyAccessService::enable()` writes
 * `contacts.login_enabled_at` and then stops. Nothing is sent. A parent whose
 * access has been granted has to be told, by a human, to go and find
 * `/family/{masjid}/sign-in`, type the address the office chose rather than the
 * one they use, and then fetch a six-digit code that dies in ten minutes
 * (`config/family.php` `login.code_ttl_minutes`). Measured at Al-Razi: ten
 * family logins enabled, five of which had never signed in once.
 *
 * ---------------------------------------------------------------------------
 * WHY NOT THE STAFF INVITE'S TABLE, OR ITS BROKER
 * ---------------------------------------------------------------------------
 *
 * `account_invite_tokens` is read by a framework password broker
 * (config/auth.php `invites`) whose whole vocabulary is a `users` row keyed by
 * `email` — `PasswordBroker::createToken()` takes a `CanResetPassword`, and the
 * table's PRIMARY KEY is the address. A parent is a `contacts` row, is not a
 * `CanResetPassword`, and an address is unique only PER TENANT here (see
 * `contacts_masjid_login_email_unique`, and the migration that added it for why
 * a globally-unique credential address would answer "is this family also at
 * that other school?"). Putting a family invite in that table would therefore
 * make one school's parent collide with another's, and hand the broker a
 * principal it would look for in `users`.
 *
 * So this is the family equivalent rather than a reuse: same lifetime, same
 * single use, same fragment-only URL, its own table and its own service.
 *
 * ---------------------------------------------------------------------------
 * THE TOKEN IS NEVER STORED
 * ---------------------------------------------------------------------------
 *
 * `token_hash` is an HMAC-SHA256 keyed on `APP_KEY`, hex, over 32 bytes of
 * CSPRNG output — the same construction `contact_login_codes.code_hash` uses and
 * for the same reason: the table alone must be worth nothing. A DB dump, a
 * replica, a backup or a support ticket must not contain a working key to a
 * specific child's photographs and safeguarding conversations.
 *
 * Keyed rather than bcrypt because the lookup is BY the digest — one indexed
 * equality, not a scan-and-verify over every live row. The entropy is in the
 * token (2^256), not in the digest's cost factor, which is what makes that
 * trade sound here and would not make it sound for a password.
 *
 * `char(64)` (hex) rather than `binary`: identical entropy, and it survives a
 * `mysqldump`, a CSV export and SQLite's dynamic typing without a
 * driver-specific column type (.claude/rules/migrations.md).
 *
 * ---------------------------------------------------------------------------
 * `login_email` IS PART OF THE CREDENTIAL, not a convenience copy
 * ---------------------------------------------------------------------------
 *
 * The row records the address the link was MAILED TO. Redemption requires it to
 * still equal the contact's current `login_email`, so an invite is dead the
 * moment the office re-addresses the login — which is the act performed when the
 * previous address was wrong, was a stranger's, or belonged to a parent who has
 * separated from the family. Without the column the link would keep opening the
 * child's file from whatever inbox happened to hold it.
 *
 * It is a snapshot, not a foreign key, for the same reason `contact_login_events`
 * snapshots its actor: the question this table has to answer after the fact is
 * "which mailbox was handed a key", and the contact's column has by then moved.
 *
 * ---------------------------------------------------------------------------
 * THREE END-STATES, and each is its own column
 * ---------------------------------------------------------------------------
 *
 *  - `consumed_at` — redeemed. Written by a compare-and-swap inside the same
 *    transaction that mints the family token, exactly like
 *    `contact_login_codes.consumed_at`, so "single-use" survives two requests
 *    arriving in the same millisecond rather than being true only when nobody
 *    double-taps.
 *  - `invalidated_at` — killed by an act on the contact: a newer invite issued,
 *    the login revoked, the address moved. Kept apart from `consumed_at` because
 *    "the parent used it" and "the office ended it" are different facts and an
 *    office reading the panel needs to be able to tell them apart.
 *  - `expires_at` — ran out.
 *
 * Rows are kept in all three states, and pruned by nothing yet. "Was a link ever
 * sent to this family, when, and did they use it?" is the question the whole
 * feature exists to make answerable; a row deleted at redemption cannot answer
 * it, and the row holds no credential (see above) so keeping it discloses
 * nothing the `contacts` row does not already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_portal_invites', function (Blueprint $table) {
            $table->id();

            // Denormalised tenant key, exactly like contact_login_codes: an
            // invite must be scopable without joining through contacts, because
            // BelongsToMasjid's global scope is the only isolation this
            // application has (.claude/rules/tenant-scoping.md).
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            // The address this link was mailed to, lower-cased — the same form
            // `FamilyLoginService::resolveContact()` compares and the same form
            // `FamilyAccessService::normalise()` stores, so the redemption check
            // is an equality and never a collation question. Production MySQL is
            // utf8mb4_bin (case-SENSITIVE), so this normalisation is the control
            // rather than a tidiness.
            $table->string('login_email');

            // HMAC-SHA256, hex. Never the token.
            //
            // UNIQUE, unlike `contact_login_codes.code_hash`, and the difference
            // is the entropy: two tenants generating the same SIX DIGITS in the
            // same minute is ordinary, so a unique index there would turn a
            // coincidence into a 500 for a parent trying to sign in. Two 256-bit
            // tokens colliding is not a thing that happens, so here the index is
            // free and it makes the lookup an exact single-row equality.
            $table->char('token_hash', 64);

            $table->timestamp('expires_at');

            // Redeemed. Compare-and-swap; see the docblock.
            $table->timestamp('consumed_at')->nullable();

            // Killed by an act on the contact rather than by the parent.
            $table->timestamp('invalidated_at')->nullable();

            // Who asked for it to be sent. 45 chars = the longest IPv6 form.
            // Nullable because a console or queue caller has no request.
            $table->string('issued_ip', 45)->nullable();

            $table->timestamps();

            // Named explicitly rather than left to Laravel's generator, which
            // would emit `contact_portal_invites_masjid_id_contact_id_expires_at_index`
            // — 59 characters, inside MySQL's 64-character identifier limit today
            // and one column name away from not being (.claude/rules/migrations.md,
            // and memory: SQLite hides MySQL's identifier limit, so the suite
            // would never tell us).
            $table->unique('token_hash', 'cpi_token_unique');

            // "The live invite for this contact" — the issue path's
            // one-live-link check and the panel's last-invite read.
            $table->index(['masjid_id', 'contact_id', 'id'], 'cpi_masjid_contact_idx');

            // A future prune sweep, and nothing else reads it yet.
            $table->index('expires_at', 'cpi_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_portal_invites');
    }
};
