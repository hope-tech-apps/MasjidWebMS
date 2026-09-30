<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order: what one checkout of a basket charged (universal cart, DECISIONS
 * 2026-09-26; design §11).
 *
 * orders — ONE ROW PER CHECKOUT ATTEMPT THAT OPENED A STRIPE PAGE.
 *
 *   A basket is the shopper's; an order is the ORGANISATION'S. That is why they
 *   differ on deletion: `carts.contact_id` cascades (login plumbing, gone with the
 *   account) while an order is a sale the office keeps — MemberAccountDeletion
 *   lists it with meal_orders and historical_orders, and `contact_id` here only
 *   nulls out. `cart_id` nulls out too, so erasing the basket never erases the sale.
 *
 *   The columns mirror historical_orders on purpose (`order_number`, `status`,
 *   `total_minor`, `fee_minor`, `currency`), so the portal's "my orders" can list
 *   nine years of imported Wix orders beside new ones without a translation layer.
 *   They are separate TABLES because historical rows were paid through Square and
 *   PayPal and every "what Manara processed" report leaves them out.
 *
 *   `charge_account_id` PINS the connected account the page was opened on, as
 *   FormResponse does: every later retrieve, expire and webhook match uses the pin,
 *   never a fresh lookup, so a change to the organisation's account cannot redirect
 *   a payment already in flight. `idempotency_key` is saved BEFORE the Stripe call,
 *   and `total_minor` is the snapshot the webhook's amount_total is checked against —
 *   never recomputed at payment time.
 *
 *   Status moves only forward and only by the webhook: pending → paid, or pending →
 *   expired. Checkout never marks anything paid.
 *
 *   `buyer_email`, `buyer_name` and `buyer_phone` are what the shopper typed at the basket
 *   page. They are the buyer's contact on an order that has no account behind it: a meal
 *   order takes the name and phone (settlement), a gift's donor falls back to all three.
 *
 * order_items — WHAT THE ORDER CHARGED FOR, frozen at checkout.
 *
 *   A snapshot, not a reference: a later price edit or a deleted dish must not
 *   change what a receipt says was bought. `record_type` / `record_id` link each line
 *   to the real record its own service created (a form response, a meal order, a
 *   donation), and `recorded_as` keeps the historical_orders vocabulary so a
 *   receipt can separate a gift from a purchase.
 *
 *   `payload` and `price_snapshot` are what SETTLEMENT will need, frozen at
 *   checkout (slice 4b). Records are created only once the payment lands, from the
 *   webhook, and by then the form may have a new price tier, the dish a new price
 *   or no row at all, and the card switch may have moved. So the webhook never
 *   re-asks any of it: it writes each line from these two columns.
 *     form     payload = the line's answers; price_snapshot = FormPayment::quote()
 *              as worked out at checkout (the shape FormResponseWriter is handed).
 *     meal     payload = {menu_item_id, meal_menu_id, name, pickup_at}; price_snapshot
 *              = the frozen line in LunchOrderLines::price()'s shape.
 *     donation payload = the giver's zakat answer when they gave one;
 *              price_snapshot = {intended_minor}.
 *   `payload` holds attendee names, so the staging scrub nulls it.
 *
 * Index names are written by hand (MySQL caps an identifier at 64 characters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid');
            $table->string('order_number', 32);

            // The basket it came from, and the buyer — both null out, never cascade:
            // the sale outlives both.
            $table->foreignId('cart_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('buyer_email', 255)->nullable();

            // Who to call about it, as typed at the basket page (slice 5, brief 5 section 3).
            // A meal order needs both (the two meal doors require a name and a phone, and the
            // kitchen board shows them), and a gift's donor falls back to them when Stripe's
            // payer details lack them. Personal data on a row that outlives the basket: the
            // staging scrub anonymises both, and an unpaid checkout loses them with the buyer's
            // account (MemberAccountDeletion), as it loses buyer_email. Nullable: an order
            // opened without them (a legacy or late one) settles with the placeholder name.
            $table->string('buyer_name', 120)->nullable();
            $table->string('buyer_phone', 32)->nullable();

            $table->string('status', 16)->default('pending');

            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->char('currency', 3)->default('usd');

            // As wide as its source, `masjids.stripe_account_id` (255): the pin is copied from that
            // column, and a narrower one would refuse an id the organisation was allowed to hold.
            $table->string('charge_account_id', 255);

            // WHAT the page charges for, as a hash of the payable lines (type, id,
            // quantity, unit price, answers) plus currency and fee. An open page is
            // handed back only when this matches — a basket changed to a DIFFERENT
            // $50 (another fund, other attendees) must never be sent to the old page,
            // whose lines the webhook would then book.
            $table->char('basket_fingerprint', 64)->nullable();

            // The ONLY routing key when the page is on a holder's account (a linked
            // organisation): the holder's Stripe users read that metadata, so they get
            // an opaque reference, never the order's public uuid or the child's id.
            $table->string('charge_ref', 40)->nullable();

            $table->string('idempotency_key', 64)->nullable();
            $table->string('stripe_checkout_session_id', 255)->nullable();
            $table->string('stripe_payment_intent_id', 255)->nullable();
            $table->timestamp('checkout_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // A refund or dispute on the basket's ONE charge, flagged on the ORDER and never
            // guessed per line: the webhook says how much of the charge was refunded, never
            // which line. `charge_flag` is refunded | partially_refunded | disputed;
            // `charge_refunded_minor` is the latest amount_refunded Stripe reported (recorded,
            // never added to); staff reconcile the lines by hand (CartPaymentService::handleChargeFlag).
            // 32, not 16: 'partially_refunded' is 18 characters, and MySQL's strict mode refuses
            // to store it (SQLite ignores the length, so no SQLite test can show it).
            // CartColumnWidthsTest pins every value the code writes against its declared width.
            $table->string('charge_flag', 32)->nullable();
            $table->unsignedBigInteger('charge_refunded_minor')->default(0);
            $table->timestamp('charge_flagged_at')->nullable();

            $table->timestamps();

            $table->unique('uuid', 'orders_uuid_unique');
            $table->unique(['masjid_id', 'order_number'], 'orders_tenant_number_unique');
            $table->unique('stripe_checkout_session_id', 'orders_checkout_session_unique');
            $table->unique('charge_ref', 'orders_charge_ref_unique');
            $table->index(['masjid_id', 'status'], 'orders_tenant_status_index');
            $table->index(['masjid_id', 'contact_id'], 'orders_tenant_contact_index');
            // Every charge.refunded / charge.dispute.created (donations and lunches too) asks
            // "is this payment intent a basket's?"; that must not scan the table.
            $table->index('stripe_payment_intent_id', 'orders_payment_intent_index');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            $table->string('buyable_type', 64);
            $table->unsignedBigInteger('buyable_id');
            $table->string('recorded_as', 16);
            $table->string('label', 255);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_amount_minor');
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3)->default('usd');

            // What settlement writes the record from, frozen at checkout (see above).
            $table->json('payload')->nullable();
            $table->json('price_snapshot')->nullable();

            // The real record the line's own service created for it.
            $table->string('record_type', 64)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();

            // The canonical hash of the BASKET line's payload as it stood at checkout
            // (PricedBasket::payloadHash). `payload` above is reshaped per type (a meal's
            // carries the dish name), so it cannot be compared with the cart's line; this can.
            // Settlement uses it to drop from the basket ONLY the lines this order paid for.
            $table->char('cart_payload_hash', 64)->nullable();

            // The donation receipt's donor link and delivery, claimed atomically by whichever
            // settlement step gets there first (CartSettlementService::donorAndReceiptStep):
            // payment_intent.succeeded and checkout.session.completed both queue one, and the
            // mail's own check-then-send is not atomic.
            $table->timestamp('receipt_claimed_at')->nullable();

            $table->timestamps();

            $table->index('order_id', 'order_items_order_index');
            $table->index(['record_type', 'record_id'], 'order_items_record_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
