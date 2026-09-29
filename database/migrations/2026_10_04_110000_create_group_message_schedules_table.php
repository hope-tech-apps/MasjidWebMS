<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_message_schedules — a NEW conversation written now and opened later
 * (T-002.4, owner 2026-09-29).
 *
 * A SEPARATE TABLE, NEVER A ROW IN group_messages WITH A FUTURE TIME. Read receipts
 * compare message ids (GroupThreadRead::covers, `last_read_message_id`), and a thread
 * "has unread" when a message is newer than a reader's bookmark. A scheduled message
 * that sat in group_messages early would take an id ahead of everything typed between
 * now and its send time, and would be counted, listed, translated, reacted to and
 * emailed about the moment it was written. So the words wait HERE, materialise as a
 * real thread and message at send time through GroupThreadWriter (the one send path),
 * and until then no reader, receipt, digest or unread count anywhere can know they
 * exist. Only NEW conversations, not replies (S11): a reply lands in a thread that has
 * a history and an audience, and holding one back would let it arrive out of order.
 *
 * Columns:
 *   kind             'thread' today; the column exists so a second kind is a value,
 *                    not a migration (varchar, never a DB enum).
 *   scope            'group' | 'participant', as group_threads.scope.
 *   about_membership_id  the child a participant conversation concerns. nullOnDelete: a
 *                    child removed from the roster leaves NULL, and the send-time gate
 *                    then refuses the item ("no longer on the roster") rather than
 *                    sending it to nobody in particular.
 *   subject, body    the words. TEXT, never varchar: the length is decided by the
 *                    request, and SQLite ignores varchar(n) (shipping.md).
 *   send_at          when it is due, UTC (datetime, never timestamp).
 *   status           scheduled -> sending -> sent, or failed / cancelled. varchar(16).
 *   failure_reason   what the teacher is shown when it did not go.
 *   sent_thread_id   the thread it became. nullOnDelete: retention may delete the
 *                    thread first, and the schedule row must survive that.
 *   retained_until   these are words about a child: bounded like a thread, and swept by
 *                    the same `groups:purge-feed`.
 *
 * author_user_id is nullOnDelete for the reason group_posts.author_user_id is: losing a
 * login must not delete the record. A schedule whose author is gone is refused at send
 * time, so a NULL author never sends.
 *
 * Indexes are hand-named at 64 characters or fewer. `gms_status_send_at_idx` is the
 * sweep's one question ("due and still scheduled"); `gms_masjid_group_status_idx` is
 * the Scheduled list's ("this class's pending and failed items").
 *
 * Additive only: a new table, no existing column, index or row touched. Blueprint only,
 * no raw SQL, no driver guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_message_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind', 16)->default('thread');
            $table->string('scope', 16);
            $table->foreignId('about_membership_id')->nullable()->constrained('group_memberships')->nullOnDelete();

            $table->string('subject', 255);
            $table->text('body');

            $table->dateTime('send_at');
            $table->string('status', 16)->default('scheduled');
            $table->string('failure_reason', 500)->nullable();
            $table->foreignId('sent_thread_id')->nullable()->constrained('group_threads')->nullOnDelete();

            $table->date('retained_until')->nullable();
            $table->timestamps();

            $table->index(['status', 'send_at'], 'gms_status_send_at_idx');
            $table->index(['masjid_id', 'group_id', 'status'], 'gms_masjid_group_status_idx');
            $table->index(['masjid_id', 'retained_until'], 'gms_masjid_retained_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_message_schedules');
    }
};
