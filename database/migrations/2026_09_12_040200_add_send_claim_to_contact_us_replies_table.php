<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `contact_us_replies.sending_at` — the right to send, claimed BEFORE the mail
 * goes out (PLAN T-042d, review fix).
 *
 * ## What was wrong with `sent_at` alone
 *
 * The double-send guard was a read-then-send. The reply row was written with
 * `sent_at = null`, the request then blocked in a SYNCHRONOUS SMTP round-trip
 * (App\Mail\ContactRequestReply is deliberately not queued), and `sent_at` was
 * stamped only after the mailer came back. Everything between those two moments
 * was a window in which the row said "nobody has sent this", and the endpoint
 * believes that column:
 *
 *  - the admin's browser or a proxy gives up at 30s and the admin presses Send
 *    again while the first send is still at the relay;
 *  - two requests carrying the same key arrive at once, one loses the unique
 *    index race, re-reads the winner's row and finds `sent_at` still null.
 *
 * In both cases the second request read "not sent" and sent — a member of the
 * public who wrote in received the same reply twice. The unique index prevented
 * a duplicate ROW, never a duplicate SEND.
 *
 * ## Why a separate column and not an earlier `sent_at`
 *
 * Stamping `sent_at` before the send would make the claim atomic and would also
 * make the column lie: a row would read "delivered" from the instant the
 * attempt began, and a crash or a refused relay would leave a permanent record
 * that a person was answered when they were not. That is the exact failure the
 * `created_at` / `sent_at` split was created to prevent. So the claim gets its
 * own column and the three states stay distinguishable:
 *
 *   sending_at NULL, sent_at NULL  — recorded, nobody is sending it
 *   sending_at SET,  sent_at NULL  — a request is at the relay right now
 *   sent_at SET                    — the mailer took it; delivery is a fact
 *
 * The claim is taken by a CONDITIONAL UPDATE (`whereNull('sent_at')` and
 * `sending_at` null or stale) whose affected-row count decides the winner, so
 * exactly one request in a race can reach `Mail::send`. It is released again
 * when the send fails, which is what keeps the documented retry-after-a-relay-
 * outage path working, and it goes stale after
 * ContactRequestsController::SEND_CLAIM_TTL_SECONDS so a request killed
 * mid-send (a php-fpm terminate, say) cannot strand a reply as unsendable
 * forever. No index: the column is only ever read through the reply's primary
 * key.
 *
 * A separate migration rather than an edit to
 * `create_contact_us_replies_table`, because that migration may already have
 * run wherever this branch is deployed and an edited `up()` never runs twice —
 * the column would then be missing and every reply would 500.
 *
 * Blueprint only, so no driver guard is needed (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_us_replies', function (Blueprint $table) {
            $table->timestamp('sending_at')->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('contact_us_replies', function (Blueprint $table) {
            $table->dropColumn('sending_at');
        });
    }
};
