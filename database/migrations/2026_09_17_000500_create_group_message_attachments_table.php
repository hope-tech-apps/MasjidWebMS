<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_message_attachments — a photo a teacher sends inside a conversation.
 *
 * The same shape as group_post_attachments, column for column, because it is
 * the same arrangement (.claude/rules/private-uploads.md): the row is a pointer,
 * the bytes live on the PRIVATE group-media disk under a random name, and the
 * uploader's filename is data that never reaches the filesystem.
 *
 * THE CASCADE BELOW IS NOT THE TEARDOWN. group_messages were created with
 * "no bytes ever hang off a message, so the cascade off group_threads is safe".
 * That stops being true here. The database cascades are kept so a row can never
 * point at a message that no longer exists, but a cascade fires no model events
 * and would leave the photo on disk — so GroupThread's force-delete hook removes
 * these THROUGH THE MODEL first, and each attachment's own `deleting` hook
 * removes its file. See GroupThread::booted() and GroupMessageAttachment.
 *
 * Tenant-scoped by a denormalised masjid_id (BelongsToMasjid), like every other
 * group table, so an attachment query scopes without joining through messages.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed
 * (.claude/rules/migrations.md). The composite index is named by hand: the
 * generated name would be 59 characters, inside MySQL's 64 today but with no
 * room for a later column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_message_id')->constrained('group_messages')->cascadeOnDelete();

            // What the uploader called it. Shown to entitled readers and used as
            // the download filename; never used to build a path.
            $table->string('original_name');

            // Sniffed from the bytes at upload time, not taken from the client.
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size_bytes');

            // Stored per row so repointing config later cannot orphan what is
            // already written.
            $table->string('disk', 32);
            $table->string('path');

            $table->timestamps();

            $table->index(['masjid_id', 'group_message_id'], 'group_msg_attachments_masjid_message_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_message_attachments');
    }
};
