<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A basket, so that one person paying for several things pays once.
 *
 * MEC ran nine years of tickets and religious giving through a single Wix
 * checkout (685 orders, 2017-2026). In Manara the same purchases live behind four
 * separate Stripe flows — form fees, meal orders, registrations, donations — and
 * each opens its own Checkout Session, so buying festival tickets and paying
 * Zakat-ul-Fitr means paying twice. The owner asked for one basket
 * (DECISIONS 2026-09-26).
 *
 * carts — ONE OPEN BASKET PER SHOPPER, per organisation.
 *
 *   `masjid_id` is on the basket, not only on the lines, because a Stripe
 *   Checkout Session is created on ONE connected account: two organisations
 *   cannot share a payment, so they do not share a basket either.
 *
 *   A shopper is a `contact_id` once signed in, and a `token` before that. All
 *   685 Wix orders were placed without an account, so guest baskets are the
 *   normal case, not an edge: the token is the only handle a guest has. It is
 *   stored as a SHA-256 hash, never in the clear, because anybody holding the
 *   value can read and edit that basket — the same reason
 *   `contact_portal_invites` hashes its token.
 *
 *   `expires_at` bounds how long an abandoned basket is kept. Nothing in a
 *   basket is reserved (see cart_items), so expiry costs the shopper nothing but
 *   the retyping.
 *
 * cart_items — ONE LINE PER THING BEING BOUGHT.
 *
 *   **A line reserves nothing.** It records WHAT was chosen, not a claim on it.
 *   Tickets close, forms fill, prices are edited and pickup dates pass while a
 *   basket sits there, so every line is re-checked and re-priced against its own
 *   source at checkout, and anything gone is dropped and named before the card
 *   screen. `unit_amount_shown_minor` exists ONLY to detect that drift and tell
 *   the shopper about it — it is never what gets charged.
 *
 *   `buyable_type` / `buyable_id` name the source (a Form, a Fund). `payload`
 *   holds the answers that submission needs, so the underlying record is created
 *   by its OWN service after the money is taken, not before.
 *
 *   `recorded_as` uses the vocabulary `historical_orders.lines` already
 *   established — `donation`, `registration`, `order_only` — so a receipt can
 *   separate a gift from a purchase without a second vocabulary, and so imported
 *   and new orders can be listed together.
 *
 * Index names are written by hand (MySQL caps an identifier at 64 characters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // The shopper: a contact once signed in, a hashed token before that.
            // Both may be set — a guest who signs in keeps the basket they filled.
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->char('token_hash', 64)->nullable();

            $table->string('status', 16)->default('open');
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique('token_hash', 'carts_token_hash_unique');
            $table->index(['masjid_id', 'contact_id', 'status'], 'carts_tenant_contact_status_index');
            $table->index('expires_at', 'carts_expires_at_index');
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            $table->string('buyable_type', 64);
            $table->unsignedBigInteger('buyable_id');

            $table->string('recorded_as', 16);
            $table->string('label', 255);
            $table->unsignedInteger('quantity')->default(1);

            // What the shopper was shown when they added it. Evidence for drift,
            // never the amount charged.
            $table->unsignedBigInteger('unit_amount_shown_minor');
            $table->char('currency', 3)->default('usd');

            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['cart_id'], 'cart_items_cart_index');
            $table->index(['buyable_type', 'buyable_id'], 'cart_items_buyable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
