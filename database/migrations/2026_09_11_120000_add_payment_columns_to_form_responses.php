<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a form response an optional money leg, and a record of the bracelets
 * handed over (MEC Fall Festival; DECISIONS.md 2026-09-11).
 *
 * EVERY COLUMN IS NULLABLE OR DEFAULTED, so each row that exists today keeps
 * meaning exactly what it meant: "no money leg". Burlington's camp form and
 * every other form carry on unchanged; only a form whose settings turn payment
 * on ever writes these.
 *
 *   uuid                    the public bearer handle for the status read and the
 *                           Stripe return. Minted for every NEW row by
 *                           FormResponse's creating hook; old rows stay NULL and
 *                           never enter a money path.
 *   client_submission_key   one per form render, sent by the renderer, so a
 *                           double-tap finds the first row instead of writing a
 *                           second (for cash, a second row is a holder owing twice).
 *   client_payload_hash     a keyed digest of the cleaned answers that key was
 *                           first used with. A replay carrying DIFFERENT answers
 *                           is refused rather than handed the old row: a card
 *                           payer would pay for fewer people than they entered,
 *                           a cash holder would owe less than they took.
 *   payment_method          'online' | 'cash' | 'external' (the Wix fallback)
 *   payment_status          'unpaid' | 'paid'
 *   *_minor                 integer cents: the snapshot Stripe is charged from and
 *                           cash is reconciled against. amount_due (decimal
 *                           dollars) is KEPT for display and back-compat and is
 *                           never overwritten.
 *   marked_paid_by_user_id  who asserted a payment by hand — an admin taking cash
 *                           for an unpaid row, or recording a Wix payment. A code
 *                           holder is staff_code_id instead.
 *   collected_*             the bracelets were handed out, and by whom.
 *   status_changed_*        who re-triaged a MONEY row, and when. Cancelling one
 *                           takes it out of a holder's cash total, so that must
 *                           never happen without a name against it.
 *
 * No `->constrained()` anywhere: adding a foreign key to an existing table
 * rebuilds it on SQLite and silently drops partial indexes
 * (.claude/rules/migrations.md). The *_id columns are plain, and indexed where
 * something reads by them.
 *
 * Every index is named by hand. MySQL caps an identifier at 64 characters and
 * SQLite enforces nothing, so a generated name that is too long passes the whole
 * suite and then dies half way through a production migrate. The longest here
 * is 37.
 *
 * NULLs are distinct in a unique index on both drivers, so the legacy rows (uuid,
 * client key and idempotency key all NULL) never collide with anything.
 *
 * Blueprint only — identical on MySQL 8.4 and SQLite. No `->after()`: column
 * position means nothing to the app, and it is one more thing only MySQL checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->uuid('uuid')->nullable();
            $table->string('client_submission_key', 64)->nullable();
            $table->char('client_payload_hash', 64)->nullable();

            $table->string('payment_method', 16)->nullable();
            $table->string('payment_status', 16)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('amount_due_minor')->nullable();
            $table->unsignedBigInteger('fee_covered_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->unsignedBigInteger('marked_paid_by_user_id')->nullable();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->unsignedBigInteger('staff_code_id')->nullable();

            $table->dateTime('collected_at')->nullable();
            $table->unsignedBigInteger('collected_by_user_id')->nullable();

            $table->unsignedBigInteger('status_changed_by_user_id')->nullable();
            $table->dateTime('status_changed_at')->nullable();

            $table->unique('uuid', 'form_responses_uuid_unique');
            $table->unique('idempotency_key', 'form_responses_idempotency_key_unique');
            $table->unique(['form_id', 'client_submission_key'], 'form_resp_form_client_key_unique');
            $table->index(['form_id', 'payment_status'], 'form_resp_form_payment_idx');
            $table->index(['form_id', 'staff_code_id'], 'form_resp_form_staff_code_idx');
            $table->index(['form_id', 'collected_at'], 'form_resp_form_collected_idx');
            $table->index('collected_by_user_id', 'form_resp_collected_by_idx');
            $table->index('marked_paid_by_user_id', 'form_resp_marked_paid_by_idx');
        });
    }

    public function down(): void
    {
        // Indexes first: SQLite refuses to drop a column an index still names.
        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropUnique('form_responses_uuid_unique');
            $table->dropUnique('form_responses_idempotency_key_unique');
            $table->dropUnique('form_resp_form_client_key_unique');
            $table->dropIndex('form_resp_form_payment_idx');
            $table->dropIndex('form_resp_form_staff_code_idx');
            $table->dropIndex('form_resp_form_collected_idx');
            $table->dropIndex('form_resp_collected_by_idx');
            $table->dropIndex('form_resp_marked_paid_by_idx');
        });

        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropColumn([
                'uuid',
                'client_submission_key',
                'client_payload_hash',
                'payment_method',
                'payment_status',
                'currency',
                'amount_due_minor',
                'fee_covered_minor',
                'total_minor',
                'paid_at',
                'marked_paid_by_user_id',
                'stripe_checkout_session_id',
                'stripe_payment_intent_id',
                'idempotency_key',
                'staff_code_id',
                'collected_at',
                'collected_by_user_id',
                'status_changed_by_user_id',
                'status_changed_at',
            ]);
        });
    }
};
