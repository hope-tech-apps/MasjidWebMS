<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 content-unification: per-placement platform visibility.
 *
 * `platforms` lives on the page<->section PLACEMENT (the page_section pivot,
 * next to `order`) rather than on `sections`, because the same library section
 * can be attached to multiple pages and a given placement may target a
 * different set of platforms than another placement of the same section.
 *
 * Nullable + null = "both" (web + mobile). The Web V1 serializer normalizes a
 * null to `["web","mobile"]` so the Nuxt site can filter to web+both without
 * ever seeing a null. Non-destructive: existing rows stay null (= both).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_section', function (Blueprint $table) {
            // JSON array of platform keys, e.g. ["web","mobile"]. Null => both.
            $table->json('platforms')->nullable()->after('order');
        });
    }

    public function down(): void
    {
        Schema::table('page_section', function (Blueprint $table) {
            $table->dropColumn('platforms');
        });
    }
};
