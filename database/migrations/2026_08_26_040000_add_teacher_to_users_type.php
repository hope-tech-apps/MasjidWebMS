<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let `users.type` hold a fourth value, 'Teacher', by converting the column from
 * an ENUM to a plain string — the same convention this codebase already uses for
 * Group::KINDS and masjid_user.role (constants + app-layer validation, never a DB
 * enum), and the alternative .claude/rules/migrations.md names for exactly this.
 *
 * ## Why not just widen the enum
 *
 * `$table->enum()` is NOT a MySQL-only construct: on SQLite (the in-memory test
 * suite) it compiles to a CHECK constraint — `type in ('SuperAdmin','MasjidAdmin',
 * 'User')` — that rejects any other value. So a MySQL-only `ALTER ... MODIFY` to a
 * four-value enum would leave the test database still refusing 'Teacher', and the
 * first test to create a teacher fails with "CHECK constraint failed: type"
 * (measured on CI, 2026-08-26). Widening it on both drivers keeps that CHECK alive
 * forever; every future staff kind would need another ALTER on a live table.
 *
 * A plain string ends that: adding a staff role later is a code change, not DDL.
 * The values stay controlled at the app layer — `UserTypeRule` gates what a form
 * may set, `TeacherInviteRequest` never accepts a type at all (the controller
 * forces 'Teacher'), and `User::TYPE_ROLE_MAP` bridges only the known values — so
 * dropping the DB-level CHECK removes belt-and-braces, not the actual guard.
 *
 * ## Cross-driver, no raw SQL
 *
 * `->change()` is native in Laravel 12 (no doctrine/dbal): Blueprint emits the
 * right DDL per driver — a `MODIFY` on MySQL, a table-rebuild that drops the CHECK
 * on SQLite. It is therefore NOT a `DB::statement`, so it needs no driver guard and
 * MigrationsBootTest's raw-SQL lint does not apply. `down()` restores the original
 * three-value enum; the migrations-mysql CI job runs it against a schema with no
 * Teacher rows (rollback immediately follows migrate), so the narrowing cannot
 * reject existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // VARCHAR(20) NOT NULL DEFAULT 'User' — same nullability and default
            // the enum had; change() replaces the whole definition.
            $table->string('type', 20)->default('User')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('type', ['SuperAdmin', 'MasjidAdmin', 'User'])
                ->default('User')
                ->change();
        });
    }
};
