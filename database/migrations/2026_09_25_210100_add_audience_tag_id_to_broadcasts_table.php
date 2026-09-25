<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which contact tag a `tag` audience addressed.
 *
 * The same shape as `audience_service_id`, and for the same reason: the
 * broadcast records WHAT was addressed ("everyone tagged Volunteer") and
 * App\Services\Broadcast\BroadcastAudienceResolver answers WHO when the message
 * goes, so a scheduled send reaches the people carrying the tag at that moment.
 *
 * `nullOnDelete` so deleting a tag keeps the history of what was sent to it.
 * A tag audience whose tag is gone addresses NOBODY, never everyone — the
 * resolver checks for the null, and ContactTagsController refuses to delete a
 * tag a still-scheduled broadcast is addressed to.
 *
 * Blueprint only, so no driver guard is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->foreignId('audience_tag_id')
                ->nullable()
                ->after('audience_service_id')
                ->constrained('contact_tags')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropForeign(['audience_tag_id']);
            $table->dropColumn('audience_tag_id');
        });
    }
};
