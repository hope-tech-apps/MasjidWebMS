<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-015d — the one-time codes that turn a `contacts` row with a `login_email`
 * into a bearer token. See docs/t015-parent-identity-design.md §3.
 *
 * ---------------------------------------------------------------------------
 * THE CODE IS NEVER STORED
 * ---------------------------------------------------------------------------
 *
 * `code_hash` holds a keyed digest and nothing else. There is no column here
 * that an operator, a DB dump, a replica, a backup or a support ticket could
 * read a working credential out of, which matters more than usual because the
 * credential opens photographs of children.
 *
 * The design says "sha256". This ships an HMAC-SHA256 keyed on `APP_KEY`
 * instead, which is strictly stronger and is a deliberate departure, not a
 * drift: a BARE digest of a 6-digit code is not one-way in any useful sense —
 * the whole domain is 10^6 values, so anyone holding the table recovers every
 * live code in milliseconds and "hashed at rest" would be decoration. Keying
 * the digest means the table alone is worthless; an attacker needs the
 * application key too, which does not live in the database. `hash_equals`
 * still does the comparison, and the column is still fixed-width.
 *
 * `char(64)` (hex) rather than the design's `binary`: identical entropy, but it
 * survives a `mysqldump`, a CSV export and SQLite's dynamic typing without a
 * driver-specific column type — and .claude/rules/migrations.md's whole point
 * is that production MySQL and the SQLite suite must not diverge.
 *
 * ---------------------------------------------------------------------------
 * Why `attempts` is a column and not a cache counter
 * ---------------------------------------------------------------------------
 *
 * The 5-attempt lockout is a property of THE CODE, so it has to live and die
 * with the code. A cache key would be lost on a cache flush — an ordinary
 * deploy step — silently re-arming an attacker who was four guesses in. The
 * per-address and per-IP throttles are a different question (a rate, not a
 * fact about a record) and those DO live in the rate limiter.
 *
 * ---------------------------------------------------------------------------
 * Retention
 * ---------------------------------------------------------------------------
 *
 * Rows are kept after consumption on purpose, and pruned by
 * `family:prune-login-codes`: "which address requested a sign-in at 02:14, from
 * which IP, and was it used" is the first question asked after a suspected
 * account compromise, and a row deleted at verification time cannot answer it.
 * The row holds no credential (see above), so keeping it discloses nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_login_codes', function (Blueprint $table) {
            $table->id();

            // Denormalised tenant key, exactly like group_memberships: a code
            // must be scopable without joining through contacts, because
            // BelongsToMasjid's global scope is the only isolation this
            // application has (.claude/rules/tenant-scoping.md).
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            // HMAC-SHA256, hex. Never the code.
            $table->char('code_hash', 64);

            // How it was delivered. A plain string, not an enum: adding a
            // channel must never be `ALTER TABLE … MODIFY` on a live table
            // (.claude/rules/migrations.md). Today the only value written is
            // `email` — see App\Models\ContactLoginCode::CHANNELS for why SMS
            // is absent rather than merely unimplemented.
            $table->string('channel', 32);

            $table->timestamp('expires_at');

            // Set inside the SAME transaction that mints the token, which is
            // what makes a code single-use under concurrency rather than
            // "single-use unless two requests arrive together".
            $table->timestamp('consumed_at')->nullable();

            // Wrong guesses against THIS code.
            $table->unsignedTinyInteger('attempts')->default(0);

            // Who asked. 45 chars = the longest IPv6 form. Nullable because a
            // console or queue caller has no request.
            $table->string('requested_ip', 45)->nullable();

            $table->timestamps();

            // The verification lookup: this tenant's live codes for one
            // contact. Deliberately NOT a unique index on code_hash — two
            // tenants generating the same six digits in the same minute is
            // ordinary, and a unique index would turn that coincidence into a
            // 500 for a parent trying to sign in.
            $table->index(['masjid_id', 'contact_id', 'expires_at']);

            // The prune sweep.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_login_codes');
    }
};
