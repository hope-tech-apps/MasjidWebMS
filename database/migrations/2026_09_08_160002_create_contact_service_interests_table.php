<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a tenant's services a member asked to hear about.
 *
 * This is a notification ROUTE, not a membership or an entitlement: an interest
 * says "send me this org's Halal Kitchen posts", never "this person may read
 * something private". Anything access-bearing belongs in `group_memberships`,
 * which already models roles and guardianship.
 *
 * Carries `masjid_id` so the row obeys the same BelongsToMasjid guardrail as
 * every other CRM model — `contact_id` and `service_id` each already imply a
 * tenant, and a row where those two disagree is the bug this column makes
 * visible rather than silently routable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_service_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One interest per member per service. The upsert in
            // MemberInterestService relies on this to be idempotent under a
            // double-tap on a toggle.
            $table->unique(['contact_id', 'service_id'], 'contact_service_interests_unique');

            // The send path's query: everyone interested in service X.
            $table->index(['masjid_id', 'service_id'], 'contact_service_interests_send_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_service_interests');
    }
};
