<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * broadcasts — ONE composed message, and the record that it was sent (T-008).
 *
 * ## Why this table exists
 *
 * Manara already owns four ways to reach a congregation — the announcements
 * feed, push, the tvOS signage board and email — and today each one is a
 * separate screen with its own form. The market complaint the recon recorded
 * (docs/recon-2026-08-11.md) is exactly that: admins retype the same paragraph
 * four times and congregants still hear about the event afterwards. This row is
 * the single thing an admin composes; `broadcast_deliveries` records what
 * happened on each channel.
 *
 * It ORCHESTRATES, it does not replace. Selecting the announcement channel
 * creates an ordinary `announcements` row through the same model and the same
 * validation rules the announcements screen uses; selecting push creates an
 * ordinary `notifications` row and dispatches the existing
 * SendMasjidNotificationJob. Every pre-existing endpoint keeps working
 * untouched, and a broadcast is invisible to them.
 *
 * ## Columns worth explaining
 *
 * - `body` is ONE text an admin writes once. The announcement channel maps it
 *   onto both `announcements.details` and `announcements.text` (that model
 *   requires both, and a composer that asked for two near-identical paragraphs
 *   would defeat the point of the feature).
 * - `starts_on` / `ends_on` are the DISPLAY WINDOW, shared by the announcement
 *   channel (which needs `start_date`/`end_date`) and the signage board (which
 *   only shows what is live right now). Nullable because a push-only or
 *   email-only broadcast has no window to speak of.
 * - `audience` + `audience_contact_ids`: what the addressable channels resolve
 *   against. `everyone` and `contacts` only — mobile_app_users carries NO
 *   contact_id (see App\Models\MobileAppUser), so a device cannot be matched to
 *   a person and any finer segmentation would be invented, not derived.
 * - `scheduled_at` is nullable = "send now". A future value only delays the
 *   queued job (Laravel's own delay); there is no scheduler table and no cron
 *   sweep here on purpose.
 * - `status` is a ROLLUP of the delivery rows (sent / partial / failed), stored
 *   because it is the answer to "did my Jumu'ah notice go out?" — the question
 *   an admin asks a list screen, which must not fan out N queries to answer.
 *
 * `status`, `audience` and the channel names are plain strings, never DB enums:
 * adding a channel (SMS is the obvious next one) must not mean
 * `ALTER TABLE … MODIFY` on a live table — .claude/rules/migrations.md records
 * what that cost the test suite. The allowed sets live in PHP
 * (App\Enums\BroadcastChannel, App\Enums\BroadcastAudience) and are validated at
 * the request boundary.
 *
 * Blueprint only — no raw SQL, so no driver guard needed
 * (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // Who composed it. Nullable + nullOnDelete: the record of a send
            // must outlive the admin account that made it.
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('body');
            $table->string('link', 2048)->nullable();

            // Shared display window (announcement channel + signage board).
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->string('audience', 32)->default('everyone');
            // Explicit contact ids when audience = contacts. JSON rather than a
            // pivot table: this is a frozen snapshot of who was addressed, not a
            // live relationship, and it must not change when a contact is later
            // edited or removed.
            $table->json('audience_contact_ids')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();

            $table->string('status', 24)->default('pending');

            $table->timestamps();

            // The admin list screen: this tenant's sends, newest first.
            $table->index(['masjid_id', 'created_at']);
            // The signage/scheduled sweeps: this tenant's live-or-pending rows.
            $table->index(['masjid_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};
