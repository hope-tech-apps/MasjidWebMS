<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * masjid_sms_senders — the per-tenant originating identity, and its A2P 10DLC
 * registration state (T-009).
 *
 * .claude/rules/broadcasts.md, prerequisite 3: "a shared long code is how a
 * fleet gets its whole account blocked. Each masjid needs its own originating
 * number or alphanumeric sender … In the US this now means A2P 10DLC brand and
 * campaign registration per organisation, which is an onboarding step with a
 * human in it, not a config value."
 *
 * ## This table exists to make REFUSAL possible
 *
 * The tempting design is a platform-wide `TWILIO_FROM` number with every masjid
 * sending through it. It works in staging and it is catastrophic in production:
 * unregistered traffic on a shared long code gets the NUMBER filtered first and
 * then the ACCOUNT suspended, which takes down SMS for every organisation on
 * the platform at once — including the ones that did everything right. There is
 * therefore deliberately NO fallback number anywhere in this feature. A tenant
 * with no approved row here cannot send, and the delivery record says so in
 * words an admin can act on (App\Services\Broadcast\Channels\SmsChannel).
 *
 * ## Registration is STATE, because it is a human process
 *
 * Brand and campaign registration takes days, involves a real business identity
 * and an EIN, and can be REJECTED or later SUSPENDED by the carriers. Modelling
 * it as a boolean would lose exactly the states an operator needs to see. The
 * status vocabulary lives on the model (MasjidSmsSender::STATUSES) and the
 * column is a plain string, so adding one is never `ALTER TABLE … MODIFY` on a
 * live table (.claude/rules/migrations.md).
 *
 * Only `approved` sends. `pending`, `rejected`, `suspended` and the default
 * `unregistered` all refuse — including `pending`, because "the paperwork is
 * in" is not permission to put traffic on a carrier network.
 *
 * ## What is NOT here: credentials
 *
 * The provider account credentials are platform-level and live in
 * config/services.php (`twilio`), env-driven and unset by default, exactly like
 * the Stripe and OneSignal blocks. This table holds only the tenant's own
 * IDENTITY — its number or Messaging Service, and the registration ids the
 * operator can look up in the provider console. Nothing here is a secret, so
 * nothing here is encrypted or hidden; contrast masjid_app_publishing, which
 * holds real per-masjid secrets and therefore does both.
 *
 * `sender_label` is the identity that appears IN the message body. Carrier
 * rules require every message to identify its sender, and the label is stored
 * rather than derived so an organisation whose legal name differs from its
 * display name can register the name the carriers approved. Null falls back to
 * the masjid's name at compose time (App\Services\Sms\SmsBodyComposer).
 *
 * Blueprint only — no raw SQL, so no driver guard is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masjid_sms_senders', function (Blueprint $table) {
            $table->id();

            // One sender identity per masjid. UNIQUE, so "which number does this
            // organisation send from?" has exactly one answer — the same reason
            // masjids.user_id is unique among live rows.
            $table->foreignId('masjid_id')->unique()->constrained()->cascadeOnDelete();

            // Which provider adapter this identity belongs to. The seam is
            // provider-agnostic (App\Services\Sms\SmsProvider); twilio is simply
            // the first adapter.
            $table->string('provider', 32)->default('twilio');

            // The originating number in E.164. Either this or a Messaging
            // Service is required before the tenant may send.
            $table->string('phone_number', 20)->nullable();

            // A provider-side sender pool / Messaging Service. Preferred for
            // 10DLC because the campaign registration attaches to it, and it is
            // where provider-side Advanced Opt-Out is configured.
            $table->string('messaging_service_sid', 64)->nullable();

            // The sender identity carried in the message body itself.
            $table->string('sender_label', 64)->nullable();

            // unregistered | pending | approved | rejected | suspended
            $table->string('registration_status', 24)->default('unregistered');

            // Operator-visible provider ids for the brand and campaign, so a
            // support conversation can start from this row.
            $table->string('brand_registration_id', 64)->nullable();
            $table->string('campaign_registration_id', 64)->nullable();

            // When the operator recorded carrier approval. Set alongside the
            // status so "approved" always carries its date.
            $table->timestamp('approved_at')->nullable();

            // Free-form operator notes (rejection reason, EIN follow-up, …).
            $table->text('notes')->nullable();

            $table->timestamps();

            // The inbound webhook resolves the tenant from the number the
            // message was sent TO, unbound, on every delivery.
            $table->index('phone_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masjid_sms_senders');
    }
};
