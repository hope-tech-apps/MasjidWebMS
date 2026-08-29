<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * offerings.description — the prose a family reads BEFORE deciding to register.
 *
 * T-006a shipped the table without one because nothing public rendered an
 * offering: every consumer was an admin screen that already had the name, the
 * window and the fee plans in front of it. T-006g adds the public read
 * (GET /api/v1/offerings/{slug}) and the `offering` page section, and a
 * registration page with no room for "what is this, who is it for, what do I
 * bring" is not a page anyone can register from.
 *
 * WHY A COLUMN AND NOT `settings`. `settings` is the presentation/behaviour knob
 * bag (confirmation copy, waitlist toggle) — untyped, unvalidated, and not
 * something the admin form writes today. A description is first-class public
 * content: it is validated at the boundary, it is served to anonymous visitors,
 * and it needs to be findable by anyone reading the schema. Burying it in a JSON
 * blob would make the ONE field a public page cannot do without the only field
 * with no column.
 *
 * WHY NOT THE INTAKE FORM'S description. `forms.description` is the wording above
 * the QUESTIONS, and one form can be the intake for several offerings. Sourcing
 * the offering's public prose from it would make editing the copy on the autumn
 * semester's page silently rewrite the spring one's.
 *
 * Additive and nullable — no backfill, no default, nothing to reconcile. Every
 * production tenant has zero offerings on the day this lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offerings', function (Blueprint $table) {
            // TEXT, not string(255): this is a paragraph or three of public copy,
            // the same call `forms.description` already makes.
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('offerings', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
