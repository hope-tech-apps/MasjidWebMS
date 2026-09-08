<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A parent may choose a password. THIS REVERSES A RECORDED DECISION.
 *
 * `Contact::getAuthPassword()` carried this since T-015d:
 *
 *     "Contacts have NO password column and no password-based guard, by design
 *      — 200 families cannot be issued passwords and a school office cannot run
 *      a reset desk. Credentials are the mailbox, via the codes T-015d adds."
 *
 * Both halves of that reasoning were about the SCHOOL ISSUING credentials, and
 * both still hold: nothing here lets an office set, see, or reset a password,
 * and no password is ever mailed to anybody. What the decision did not
 * anticipate is a parent who has already proved control of their mailbox asking
 * to stop going back to it every time. That is a different act with a different
 * threat model — the credential is chosen by the person it belongs to, and the
 * office never holds it — so the column exists and the reset desk still does
 * not. A parent who forgets it uses the code flow, which never went away.
 *
 * ---------------------------------------------------------------------------
 * WHY NULLABLE, AND WHY THAT IS THE SECURITY PROPERTY
 * ---------------------------------------------------------------------------
 *
 * NULL is every existing row and stays the default forever. `getAuthPassword()`
 * coalesces NULL to the empty string, which every hasher in the framework
 * refuses before doing any work — so a contact who has not chosen a password
 * cannot be signed in by ANY password, including an empty one. The fail-closed
 * behaviour the original docblock built is preserved exactly; it is now the
 * behaviour for un-enrolled rows rather than for all of them.
 *
 * `password_set_at` is operator and audit visibility — it answers "does this
 * family have a password?" on the admin panel without the panel touching the
 * hash. Nothing authorizes on it.
 *
 * ---------------------------------------------------------------------------
 * The column is 255 and not fillable
 * ---------------------------------------------------------------------------
 *
 * 255 because bcrypt is 60 today and argon2id is longer; a hash column sized to
 * the current algorithm is a migration waiting for the next one. Absent from
 * `Contact::$fillable` for the same reason the four `login_*` columns are: every
 * write path into that array is a CRM request body, and a roster import must
 * never be able to set a credential as a side effect of correcting a phone
 * number.
 *
 * Anchored `after('last_login_at')` — a T-015d column that exists on every
 * environment — rather than after anything added this week, so ordering does
 * not depend on which other migrations have run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('password', 255)->nullable()->after('last_login_at');
            $table->timestamp('password_set_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['password', 'password_set_at']);
        });
    }
};
