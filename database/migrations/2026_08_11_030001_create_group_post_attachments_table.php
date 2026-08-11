<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_post_attachments — one image belonging to one feed post.
 *
 * These are photographs of children. The bytes live on the PRIVATE disk
 * (config('groups.media.disk') = storage/app/private, NOT the web-exposed public
 * root, and NOT spatie/laravel-medialibrary, whose `media` table carries no
 * masjid_id). This table is the only map from a post to those bytes, and the
 * only place the uploader's original filename is kept — the path on disk is
 * random, so an image cannot be found by guessing a name.
 * See .claude/rules/private-uploads.md.
 *
 * `masjid_id` is denormalised for the same reason it is on group_memberships:
 * every read of a child's photo should carry the tenant predicate without
 * joining two tables to find it. MySQL has no row-level security, so that
 * predicate is the whole guarantee.
 *
 * The FK cascade below is a BACKSTOP ONLY. Deleting a post removes its
 * attachments through the model (GroupPost's `deleting` hook, on force delete)
 * so the FILES go with them — a DB-level cascade fires no model events and would
 * leave orphaned bytes on disk forever.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed
 * (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_post_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_post_id')->constrained('group_posts')->cascadeOnDelete();

            // What the uploader called it. Shown to entitled readers and used as
            // the download filename; never used to build a path.
            $table->string('original_name');

            // Sniffed from the bytes at upload time, not taken from the client.
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size_bytes');

            // The disk is stored per row rather than read from config at
            // download time, so repointing the config later cannot orphan what
            // is already written.
            $table->string('disk', 32);
            $table->string('path');

            $table->timestamps();

            // The download path: this tenant's attachment, under its post.
            $table->index(['masjid_id', 'group_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_post_attachments');
    }
};
