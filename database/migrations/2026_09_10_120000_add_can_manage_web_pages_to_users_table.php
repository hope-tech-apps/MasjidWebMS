<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-account grant that shows Web Pages Management to a MasjidAdmin.
 *
 * The menu item is SuperAdmin-only by `allowed_types`, and the owner wants it
 * opened to ONE named administrator, not to every masjid's admins at once.
 *
 * A column rather than a spatie permission: `Permission::count() === 8` is a
 * pinned invariant (five tests). The spatie layer is the CRM's, and every
 * non-CRM capability so far — teacher, lunch staff, broadcasts — has been kept
 * out of it on purpose.
 *
 * This is menu visibility, not authorization. The pages/sections API sits in
 * the `admin` + `tenant` group and has always answered any MasjidAdmin for
 * their own masjid (403 for any other); nothing about that changes. Default
 * false, so no one's screen changes until an account is granted.
 *
 * Blueprint only — identical on MySQL and SQLite, so no driver guard is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_manage_web_pages')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_manage_web_pages');
        });
    }
};
