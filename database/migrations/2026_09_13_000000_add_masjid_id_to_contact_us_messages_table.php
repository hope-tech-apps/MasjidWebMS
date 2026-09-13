<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The organisation a contact-us message was sent TO, as a column of its own.
 *
 * ## This reverses a decision recorded one migration ago
 *
 * `add_answered_state_to_contact_us_messages_table` says, in as many words, "No
 * `masjid_id` is added" — the tenant was derived through
 * contacter -> mobileAppUser -> masjid_id by every query that touched the table.
 * That paragraph was right for the world it was written in and is superseded
 * here, because the world changed underneath it.
 *
 * The apps gained an organisation switcher. A member whose handset is registered
 * with its HOME organisation can now walk into one of that organisation's listed
 * children and send a message there — and their device row stays pinned to home,
 * because switching re-registers nothing. So the derivation and the delivery now
 * answer different questions:
 *
 *   - `ContactUsNotifier::received()` emails the staff of the organisation in
 *     the ROUTE — the child. Correct: that is who the member wrote to.
 *   - `ContactRequestsController::ownedBy()` derived the organisation from the
 *     DEVICE — the parent. So the message would arrive in the child's staff
 *     inboxes as an email and then be listable only by the parent's admins,
 *     where nobody is looking for it and the child's admins cannot open it at
 *     all.
 *
 * A message that is emailed to one organisation and filed under another is the
 * silent-failure shape this codebase keeps meeting: everything returns success
 * and the work quietly lands where nobody will find it. The fix is to stop
 * deriving. The organisation is a FACT about the message — the one the sender
 * chose — so the message carries it.
 *
 * ## Nullable, and `nullOnDelete`, both deliberately
 *
 * NOT NULL would be the stronger column, and it is not available: the value has
 * to be backfilled from live rows, and a message whose device has been orphaned
 * (`mobile_app_users.masjid_id` is itself nullable and `on delete set null`)
 * has no organisation to backfill FROM. Those rows are ALREADY invisible in
 * every inbox today — `whereHas` skips them — so leaving them null loses
 * nothing, while NOT NULL would abort the deploy over them.
 *
 * `nullOnDelete` matches `mobile_app_users.masjid_id` rather than cascading. A
 * cascade here would DELETE messages that survive an organisation's deletion
 * today, which is a bigger change than this migration is entitled to make.
 *
 * What stops a NEW null appearing is above the database: both intake
 * controllers write the organisation they resolved, and `ContactUsMessage`'s
 * `creating` hook fills the column from the sender's device for any other
 * writer. See the model.
 *
 * ## The backfill reproduces the old join exactly
 *
 * Same three tables, same direction, so every message an office can list today
 * is listable at the same place tomorrow. It runs in chunks through the query
 * builder — no `DB::statement`, so nothing here is dialect-specific and
 * `.claude/rules/migrations.md`'s driver guard does not apply. SQLite has no
 * `UPDATE ... JOIN`, which is the reason it is a loop rather than one statement.
 *
 * The composite index is named BY HAND. MySQL caps an identifier at 64
 * characters and SQLite enforces no such limit, so a generated name that is too
 * long passes 3000 green tests and then kills a migration halfway through a
 * production deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_us_messages', function (Blueprint $table) {
            $table->foreignId('masjid_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();

            // The inbox's whole query: this organisation's messages, newest
            // first. 34 characters, well inside MySQL's 64-character ceiling.
            $table->index(['masjid_id', 'created_at'], 'contact_us_msgs_masjid_created_idx');
        });

        $this->backfillFromTheDeviceTheInboxJoinedThrough();
    }

    public function down(): void
    {
        Schema::table('contact_us_messages', function (Blueprint $table) {
            $table->dropIndex('contact_us_msgs_masjid_created_idx');
            $table->dropConstrainedForeignId('masjid_id');
        });
    }

    /**
     * Fill the new column the way `ContactRequestsController::ownedBy()` derived
     * it before this migration existed: message -> contacter -> mobileAppUser.
     *
     * PUBLIC so a test can replay the backfill over a hand-built pre-migration
     * state without also replaying the DDL. The alternative — asserting the
     * backfill only through a full `down()`/`up()` — makes the one part of this
     * migration that touches customer data the part that is hardest to exercise.
     *
     * Only rows that are still null are written, so running it twice is a no-op
     * and it can never overwrite an organisation somebody set deliberately.
     */
    public function backfillFromTheDeviceTheInboxJoinedThrough(): void
    {
        DB::table('contact_us_accounts')
            ->join('mobile_app_users', 'mobile_app_users.id', '=', 'contact_us_accounts.mobile_app_user_id')
            ->whereNotNull('mobile_app_users.masjid_id')
            ->select([
                'contact_us_accounts.id as account_id',
                'mobile_app_users.masjid_id as masjid_id',
            ])
            ->orderBy('contact_us_accounts.id')
            ->chunk(500, function ($accounts) {
                // Grouped so it is one UPDATE per organisation per chunk rather
                // than one per message.
                foreach ($accounts->groupBy('masjid_id') as $masjidId => $group) {
                    DB::table('contact_us_messages')
                        ->whereNull('masjid_id')
                        ->whereIn('contact_us_account_id', $group->pluck('account_id')->all())
                        ->update(['masjid_id' => (int) $masjidId]);
                }
            });
    }
};
