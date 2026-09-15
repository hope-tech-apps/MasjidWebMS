<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one lever that takes `GET /mobile/masjids/{id}/menu` away from every
 * phone at once, and the record of who pulled it and why.
 *
 * The new side menu is derived from the switches; the apps' fallback menu is
 * derived from the legacy /features. If the derivation is ever wrong — a menu
 * missing Donate for a whole organisation, a tab bar that lost Contact — the
 * fix has to be something an operator can do in one command, tonight, without a
 * new app release and without waiting on an app store. Setting this row makes
 * /menu answer 404, which both clients read as "menu unavailable" and answer by
 * falling back to the menu they already know how to build.
 *
 * A TABLE and not a .env value on purpose. Editing .env means `config:cache`,
 * and a bad .env plus config:cache is how every request on this box once
 * returned 500 (memory: env-edit-config-cache-outage). A lever whose failure
 * mode is worse than the thing it fixes is not a lever.
 *
 * One row, read through AppMenu::killed() behind a 60 s cache key. No row at
 * all means NOT killed — the table is allowed to be empty, and this migration
 * deliberately seeds nothing, so the deploy that creates it changes no
 * behaviour. Setting the row on production is a separate, deliberate act
 * (`php artisan app-menu:kill`).
 *
 * Column choices:
 *  - No foreign key on `updated_by`: it records the NAME of the human being at
 *    the shell, which is the only identity a console command has, and the row
 *    must survive whatever happens to any user record.
 *  - `reason` is optional. An emergency lever that refuses to fire until you
 *    have typed a sentence is an emergency lever that gets worked around; the
 *    command asks for one loudly and records it when given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_menu_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('menu_disabled')->default(false);
            $table->string('reason', 255)->nullable();
            $table->string('updated_by', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_menu_settings');
    }
};
