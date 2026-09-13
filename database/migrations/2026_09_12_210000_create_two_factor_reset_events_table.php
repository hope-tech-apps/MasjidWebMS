<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger of every second factor a platform operator has cleared for
 * somebody else — the one act in the 2FA design that is not performed by the
 * account's own owner.
 *
 * ## Why an operator door exists at all
 *
 * A confirmed enrolment could brick an account permanently. The only three
 * doors out of it — `enroll` (422 while confirmed), `recovery-codes` and
 * `disable` — each demand a code the stranded admin no longer has, and nothing
 * else in the application ever writes a `two_factor_*` column. Lose the phone
 * and burn the printed sheet and that administrator is locked out of the
 * platform for good, recoverable only by an UPDATE typed into the production
 * database by whoever happens to hold the credentials for it — untraceable,
 * unreviewable, and available to more people than this endpoint is.
 *
 * ## Why the ledger is part of the door, not a nice-to-have
 *
 * Clearing somebody else's second factor is the single most dangerous act the
 * platform permits: a SuperAdmin can already set any staff password
 * (UsersController::update), so the second factor is the ONE thing standing
 * between a rogue or stolen SuperAdmin session and every account on the
 * platform. The mitigation cannot be "we trust SuperAdmins" — it has to be that
 * the act is impossible to perform anonymously and impossible to perform
 * silently. This table is the first half (a named actor, a typed reason, a
 * timestamp and the address it came from); the email to the affected admin is
 * the second half.
 *
 * ## Column choices
 *
 * `user_id` / `performed_by_user_id` are `nullOnDelete`, and each is shadowed by
 * an EMAIL SNAPSHOT captured at the time of the act. An audit row that
 * disappears when somebody deletes the account it is about is not an audit row;
 * an audit row that BLOCKS that deletion is a retention bug we have shipped
 * before (see the append-only guard that made account erasure impossible).
 * Nulling the FK and keeping the snapshot satisfies both: the history survives
 * the account, and the account can still be erased. A hard erasure request
 * scrubs the two snapshot columns and leaves the rest of the row standing.
 *
 * `reason` is required at the request layer (min 10 characters). "Because I was
 * asked to" is a bad answer, but a blank one is not an answer at all, and the
 * value of this row is that a reviewer six months later can tell the two apart.
 *
 * `created_at` only. Nothing updates one of these rows, and no application code
 * deletes one — enforced in the model rather than by a database trigger,
 * deliberately: a trigger here would make the users table undeletable all over
 * again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_reset_events', function (Blueprint $table) {
            $table->id();

            // Whose second factor was cleared.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_email')->nullable();

            // Who cleared it. NEVER nullable in practice for the dashboard door
            // — the request cannot be made unauthenticated — but nulled if that
            // operator's own account is later removed, which is why the label
            // below is written at the same time and never derived on read.
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('performed_by_label');

            // 'dashboard' (a signed-in SuperAdmin who passed their own second
            // factor) or 'console' (the platform operator on the server, who is
            // the only answer left when the LAST SuperAdmin is the stranded one).
            $table->string('channel', 32)->default('dashboard');

            $table->text('reason');
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The question this table is asked is always "what has been done to
            // this account" or "what has this operator done", newest first.
            $table->index(['user_id', 'created_at'], 'tfre_user_created_idx');
            $table->index(['performed_by_user_id', 'created_at'], 'tfre_actor_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_reset_events');
    }
};
