<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_messages — one message inside a group thread (PLAN T-005c).
 *
 * Body text ONLY, deliberately: the feed owns media
 * (group_post_attachments + the private-disk pipeline), and thread attachments
 * are deferred entirely rather than half-built. Because no bytes ever hang off
 * a message, the cascade off group_threads is safe here in a way it was NOT for
 * the feed — a DB cascade fires no model events, which orphans files on disk
 * forever but orphans nothing when the rows are all there is
 * (.claude/rules/private-uploads.md explains the contrast).
 *
 * AUTHOR IS A USER, NOT A CONTACT — the same reasoning as group_posts: a
 * Contact cannot authenticate anywhere in this application, so attributing a
 * message to one would record a claim the server never verified. The
 * parent-facing side (T-015) will come through GroupAudience::identitiesFor(),
 * not through a new author column. nullOnDelete so retiring a staff account
 * keeps the conversation history readable.
 *
 * No softDeletes: deletion follows the THREAD (soft-delete the thread to hide
 * the conversation; the purge hard-deletes both). A per-message delete surface
 * would invite silently rewriting what was said to a parent.
 *
 * Tenant-scoped by a denormalised masjid_id, same arrangement as
 * group_memberships, so BelongsToMasjid scopes message queries without joining
 * through threads. Proven by tests/Feature/GroupMessagingTenantIsolationTest.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed
 * (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_thread_id')->constrained()->cascadeOnDelete();

            // The admin account that wrote it — the AUTHENTICATED principal,
            // never a client-supplied name. See the class docblock.
            $table->foreignId('author_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('body');

            $table->timestamps();

            // The conversation read: one tenant's thread, oldest first.
            $table->index(['masjid_id', 'group_thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_messages');
    }
};
