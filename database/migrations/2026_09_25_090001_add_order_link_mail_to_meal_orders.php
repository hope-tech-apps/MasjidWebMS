<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order-link email (owner, 2026-09-24): a customer who gave an email gets a
 * link back to their order, where they can change it until the cutoff.
 *
 *  - `site_origin` is the site the order was placed FROM (the public page is
 *    proxied onto masjids' own domains), recorded at placement only when the
 *    browser's Origin is one the CORS allowlist already trusts
 *    (JummahLunchOrdersController::siteOrigin). The email for an online order is
 *    sent from the webhook, long after that request is gone, so the link has to
 *    be remembered. NULL means "use APP_URL", which is what the Stripe return
 *    URLs have always fallen back to.
 *  - `confirmation_sent_at` stamps the confirmation email. It is claimed with a
 *    conditional UPDATE before the mail is queued, so the two success events
 *    Stripe sends for one payment, or a replay, send it once.
 *
 * Neither column is personal data; neither name matches the staging scrub's PII
 * tokens. No index: both are read with the row, never filtered on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->string('site_origin')->nullable();
            $table->dateTime('confirmation_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meal_orders', function (Blueprint $table) {
            $table->dropColumn(['site_origin', 'confirmation_sent_at']);
        });
    }
};
