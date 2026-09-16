<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which shell the app draws: the new side menu, or the layout it shipped with.
 *
 * The R1 apps contain BOTH layouts. `navigation` is how an operator chooses
 * between them per organisation and per platform without an app release —
 * the lever for the case where the new menu is wrong for one organisation but
 * fine everywhere else, which `app-menu:kill` (all phones, all orgs) is far
 * too blunt for.
 *
 * It lives on `app_version_settings` because that row is already the app's
 * per-platform control surface (force update, maintenance mode), already
 * cached under one key, and already flushed by the one admin screen that
 * writes it. A second table would be a second thing to flush.
 *
 * Nullable, and null is the default for every existing row: a null means "the
 * client's compiled default", the emission side omits the key entirely, and so
 * this migration leaves every app-config body byte-identical to what it was
 * before the deploy. Turning the lever is a separate, deliberate act.
 *
 * Values, and why the column is 20 and not an enum:
 *
 *   `menu`   the R1 shell — hybrid tab bar plus the side menu
 *   `legacy` today's layout, on its own old data path
 *
 * The clients ALSO understand `side_menu` and `tabs_drawer`, the vocabulary
 * they were compiled with, and the request rule accepts all four (see
 * config/app_menu.php, `navigation_aliases`). An enum column would have made
 * accepting the second pair a migration; a varchar makes it a validation rule.
 * That matters because both clients map an unknown value to the NEW shell — a
 * rejected-then-mistyped spelling would look like a lever that reported
 * success and changed nothing.
 *
 * What it does NOT roll back, stated here because it is the thing most likely
 * to be assumed: `legacy` returns the menu, the store and the drawer. It does
 * not return the Android single-activity merge, the iOS HomeView de-nesting or
 * the in-place switch — the legacy shell shares all three. Those need a build.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_version_settings', function (Blueprint $table) {
            $table->string('navigation', 20)->nullable()->after('maintenance_message');
        });
    }

    public function down(): void
    {
        Schema::table('app_version_settings', function (Blueprint $table) {
            $table->dropColumn('navigation');
        });
    }
};
