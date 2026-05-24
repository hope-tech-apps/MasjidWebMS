<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * splash_announcements
 *
 * Per-masjid splash / in-app message. Surfaces on:
 *   - the public Nuxt site (modal on first session load, fetched from the
 *     /mobile/masjids/{id}/splash endpoint)
 *   - mobile apps via OneSignal In-App Messages — the row is mirrored to
 *     OneSignal on save and the mobile SDK handles display/dismiss
 *
 * Only one splash is "live" at a time per masjid — the public endpoint
 * returns the highest-priority row that's currently within its scheduled
 * window. priority ties broken by latest created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('splash_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // Content
            $table->string('title');
            $table->text('body')->nullable();           // sanitized rich text (rendered via SafeHtml / useSafeHtml)

            // Optional call-to-action button
            $table->string('cta_label', 120)->nullable();
            $table->string('cta_url', 2048)->nullable();

            // Scheduling — both required; the controller filters by now() between starts_at and ends_at
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            // Tie-break for overlapping windows. Higher wins. Default 0.
            $table->unsignedInteger('priority')->default(0);

            // Soft toggle so admins can hide a splash without deleting it.
            $table->boolean('is_active')->default(true);

            // OneSignal IAM id returned by the IAM REST API on create. Stored so we can
            // PUT updates / DELETE on remove without re-querying OneSignal.
            $table->string('onesignal_iam_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Hot lookup: "which splash is live for this masjid right now?"
            $table->index(['masjid_id', 'is_active', 'starts_at', 'ends_at'], 'splash_lookup_idx');
            $table->index(['masjid_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('splash_announcements');
    }
};
