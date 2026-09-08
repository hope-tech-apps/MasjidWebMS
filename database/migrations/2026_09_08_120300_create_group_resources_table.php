<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_resources — files a teacher keeps for a class. Worksheets, a syllabus,
 * a handout to send home.
 *
 * ## THE LEAK THIS SCHEMA CANNOT CLOSE, STATED PLAINLY
 *
 * The server cannot read inside a PDF. A teacher who uploads
 * "Progress reports Sept.pdf" and marks it visible to families publishes a
 * document naming every child in the class to every guardian in it. No column
 * here can prevent that. What stands between the school and it is only:
 *
 *   1. `visibility` DEFAULTS TO 'staff' — the fail-closed direction. A file is
 *      private until somebody deliberately says otherwise.
 *   2. Visibility is PER FILE, never per class. There is no "share this folder".
 *   3. The UI names the consequence at the moment of the choice, with the
 *      guardian count in it, rather than after the fact.
 *
 * Anyone widening this feature — a bulk visibility toggle, a default of
 * 'families', a folder — is removing one of those three. Do not.
 *
 * ## THESE BYTES ARE NOT BACKED UP
 *
 * .claude/rules/backups.md: App\Support\Backup\MediaTarget derives its path from
 * `config('media-library.disk_name')`, the PUBLIC disk. This table writes to the
 * PRIVATE disk, which no backup target covers. Every worksheet uploaded here is
 * one disk failure from gone. Said here so it is a known limitation rather than
 * a discovery.
 *
 * ## Path columns
 *
 * `original_name` is a DOWNLOAD FILENAME ONLY and is never used to build a path
 * — it is attacker-controlled text. The stored `path` is a random 40-character
 * name with a SNIFFED extension, under <root>/<masjid_id>/<group_id>/, exactly
 * as group_post_attachments does it. `disk` is stored per row so that repointing
 * the config later cannot orphan files already written.
 *
 * No soft deletes: the model's `deleting` hook removes the bytes, and a
 * soft-deleted row would keep a file alive that nothing can reach. No
 * `retained_until` and not in `groups:purge-feed` — a worksheet is not a record
 * about a child, and a class's files die with the class (see Group::booted,
 * which must delete these through the MODEL so the hook fires; the DB cascade
 * alone fires no model events and would strand every file on disk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // The DB cascade here is a BACKSTOP ONLY. Bytes are removed by the
            // model's deleting hook, which a database-level cascade never fires.
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();

            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();

            // staff | families. Defaults to the private one on purpose.
            $table->string('visibility', 16)->default('staff');

            $table->string('original_name');
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk', 32);
            $table->string('path');

            $table->timestamps();

            // The listing read: this class's files, for this audience.
            $table->index(['masjid_id', 'group_id', 'visibility'], 'group_resources_audience_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_resources');
    }
};
