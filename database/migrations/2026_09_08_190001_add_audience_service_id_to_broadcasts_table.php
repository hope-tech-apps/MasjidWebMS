<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which service a `service` audience addressed.
 *
 * ---------------------------------------------------------------------------
 * THE SERVICE IS SNAPSHOTTED; THE RECIPIENTS ARE NOT
 * ---------------------------------------------------------------------------
 * `audience_contact_ids` snapshots a chosen list so a later directory edit
 * cannot rewrite who was addressed. A service audience is the opposite case and
 * deliberately does NOT snapshot its people.
 *
 * An interest is an OPT-IN, and the SMS work already settled how opt-ins are
 * treated: the audience resolver filters on the consent record at SEND time, not
 * on a list captured earlier. Freezing the interested set at compose time would
 * mean a member who withdrew their interest in the minutes before dispatch still
 * received the message — honouring a withdrawal is the whole point of storing
 * one. So the broadcast records WHAT was addressed ("everyone interested in the
 * Halal Kitchen") and the resolver answers WHO at the moment of sending.
 *
 * Nullable because every other audience leaves it empty, and constrained with
 * `nullOnDelete` so deleting a service does not delete the history of what was
 * sent about it — the broadcast row remains, its audience simply no longer
 * names a live service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->foreignId('audience_service_id')
                ->nullable()
                ->after('audience_contact_ids')
                ->constrained('services')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropForeign(['audience_service_id']);
            $table->dropColumn('audience_service_id');
        });
    }
};
