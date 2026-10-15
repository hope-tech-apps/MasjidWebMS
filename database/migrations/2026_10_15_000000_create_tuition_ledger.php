<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_billing_settings', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->unique()->constrained('masjids')->restrictOnDelete();
            $t->string('currency', 3); $t->unsignedInteger('minimum_payment_minor')->default(0);
            $t->unsignedTinyInteger('remind_before_days')->nullable(); $t->unsignedTinyInteger('remind_after_days')->nullable(); $t->timestamps();
        });
        Schema::create('tuition_accounts', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->string('currency', 3); $t->unsignedInteger('minimum_payment_minor')->default(0);
            $t->unsignedTinyInteger('remind_before_days')->nullable(); $t->unsignedTinyInteger('remind_after_days')->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps();
        });
        Schema::create('tuition_children', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('tuition_accounts')->restrictOnDelete();
            $t->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $t->date('starts_on'); $t->date('ends_on')->nullable(); $t->timestamps();
            $t->unique(['masjid_id', 'contact_id'], 'tuition_child_org_uq');
        });
        Schema::create('tuition_guardians', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('tuition_accounts')->restrictOnDelete();
            $t->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $t->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at'); $t->timestamps();
            $t->unique(['account_id', 'contact_id'], 'tuition_guardian_account_uq');
        });
        Schema::create('tuition_schedules', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('fee_plan_id')->constrained('fee_plans')->restrictOnDelete();
            $t->foreignId('school_year_id')->constrained('school_years')->restrictOnDelete();
            $t->string('cadence', 10); $t->unsignedTinyInteger('due_day')->default(9);
            $t->unsignedTinyInteger('sibling_percent')->default(0); $t->json('group_ids'); $t->json('grades');
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps();
            $t->unique('fee_plan_id', 'tuition_schedule_plan_uq');
        });
        Schema::create('tuition_checkouts', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('tuition_accounts')->restrictOnDelete();
            $t->foreignId('payer_contact_id')->constrained('contacts')->restrictOnDelete();
            $t->string('uuid', 64)->unique(); $t->unsignedBigInteger('amount_minor'); $t->bigInteger('balance_minor');
            $t->string('currency', 3); $t->string('stripe_account_id');
            $t->string('stripe_session_id')->nullable(); $t->text('checkout_url')->nullable(); $t->timestamps();
        });
        Schema::create('tuition_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('tuition_accounts')->restrictOnDelete();
            $t->foreignId('child_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $t->foreignId('payer_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $t->foreignId('fee_plan_id')->nullable()->constrained('fee_plans')->restrictOnDelete();
            $t->foreignId('checkout_id')->nullable()->constrained('tuition_checkouts')->restrictOnDelete();
            $t->foreignId('original_line_id')->nullable()->constrained('tuition_lines')->restrictOnDelete();
            $t->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('recorded_by_label'); $t->string('kind', 24); $t->bigInteger('amount_minor'); $t->string('currency', 3);
            $t->date('effective_on'); $t->date('due_on')->nullable(); $t->string('period', 64)->nullable();
            $t->string('reason', 1000); /* family-visible; reason is staff-only */ $t->string('description', 255)->nullable(); $t->string('method', 24)->nullable(); $t->string('reference')->nullable();
            $t->string('source', 24); $t->string('idempotency_key', 150); $t->string('stripe_payment_intent_id')->nullable();
            $t->boolean('needs_review')->default(false); $t->timestamp('created_at')->useCurrent();
            $t->unique(['masjid_id', 'idempotency_key'], 'tuition_line_key_uq');
            $t->index(['account_id', 'effective_on'], 'tuition_account_date_idx');
        });
        Schema::create('tuition_mails', function (Blueprint $t) {
            $t->id(); $t->foreignId('masjid_id')->constrained('masjids')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('tuition_accounts')->restrictOnDelete();
            $t->foreignId('guardian_contact_id')->constrained('contacts')->restrictOnDelete();
            $t->string('mail_key', 150); $t->string('kind', 24); $t->string('status', 16)->default('queued');
            $t->date('from_on'); $t->date('to_on'); $t->json('due_items')->nullable();
            $t->timestamp('attempted_at')->nullable(); $t->timestamp('sent_at')->nullable();
            $t->timestamp('failed_at')->nullable(); $t->string('exception_class')->nullable(); $t->timestamps();
            $t->unique(['masjid_id', 'mail_key'], 'tuition_mail_key_uq');
        });
    }

    public function down(): void
    {
        foreach (['tuition_mails', 'tuition_lines', 'tuition_checkouts', 'tuition_schedules', 'tuition_guardians', 'tuition_children', 'tuition_accounts', 'organisation_billing_settings'] as $table) Schema::dropIfExists($table);
    }
};
