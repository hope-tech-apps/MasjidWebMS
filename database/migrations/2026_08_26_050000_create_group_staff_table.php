<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `group_staff` — which staff Users lead which classes.
 *
 * A "teacher" today is only a `group_memberships.role = 'leader'` row, and that row
 * points at a **Contact**, not a login. It is a label on a roster, not an identity.
 * A Teacher who actually signs in is a `User` (users.type = 'Teacher'), and there is
 * no table linking a User to the classes they teach — so this adds it.
 *
 * This pivot, not the legacy Contact `leader`, is the SOLE authority for teacher
 * standing: `GroupAudience` reads group_staff to decide whether a User principal
 * leads a group, independent of the email-matched-Contact bridge that parents and
 * legacy leaders still use. Keeping the two paths separate is deliberate — routing a
 * teacher through the Contact `confirmed()` path would either grant nothing (a
 * teacher has no self-asserted membership to confirm) or leak standing to a Contact
 * that merely shares an email.
 *
 * ## Shape, and why each part of it exists
 *
 *   group_staff: id, masjid_id FK→masjids, group_id FK→groups, user_id FK→users,
 *                role varchar(32) default 'teacher',
 *                assigned_by_user_id nullable FK→users, assigned_at dateTime null,
 *                timestamps
 *     unique (group_id, user_id)     -- one staff row per person per class
 *     index  (masjid_id, user_id)    -- "the classes THIS teacher leads" (the hot path)
 *     index  (masjid_id, group_id)   -- "the staff OF this class"
 *
 * `masjid_id` is carried on the row (the `BelongsToMasjid` convention) so the tenant
 * global scope constrains every read without a join, and so a group_staff row can
 * never silently address another tenant's group. `role` is a plain varchar, not an
 * enum — the masjid_user precedent — so a future staff role (assistant, aide) is a
 * write, not an `ALTER TABLE ... MODIFY` on a live table (.claude/rules/migrations.md).
 *
 * `assigned_by_user_id` records which admin made the assignment (nullable, and
 * `nullOnDelete` rather than cascade: if that admin is later deleted the assignment
 * itself must survive — the teacher still leads the class). `assigned_at` is
 * `dateTime`, NOT `timestamp()`: production runs with `explicit_defaults_for_timestamp`
 * OFF, where a nullable `timestamp` acquires an implicit auto-update; `dateTime` has no
 * such footgun.
 *
 * Both principal foreign keys cascade on delete, matching masjid_user: a staff
 * assignment is an access grant, and if the class or the user is really gone the grant
 * goes with it rather than dangle at an id a later row could reuse. Soft-deletes leave
 * it intact (a restored class comes back with its teachers).
 *
 * The unique key and both indexes are declared BEFORE the explicit `foreign(...)`
 * calls — and this is why the `foreignId(...)->constrained()` shorthand is avoided —
 * so InnoDB reuses them for the FK columns instead of auto-creating a second, duplicate
 * index on each (the masjid_user migration documents the same reasoning at length).
 *
 * `down()` drops the table and everything with it; no driver branch is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id');
            $table->foreignId('group_id');
            $table->foreignId('user_id');

            // Advisory. Which kind of staff this is; teachers are the only kind today.
            $table->string('role', 32)->default('teacher');

            // The admin who made the assignment, and when. Nullable because a
            // backfilled or system-made assignment may have no actor.
            $table->foreignId('assigned_by_user_id')->nullable();
            $table->dateTime('assigned_at')->nullable();

            $table->timestamps();

            // One staff row per person per class.
            $table->unique(['group_id', 'user_id']);

            // The two access patterns: the classes a teacher leads, and the staff
            // of a class. Both lead with masjid_id so the tenant scope uses them.
            $table->index(['masjid_id', 'user_id']);
            $table->index(['masjid_id', 'group_id']);

            // Declared AFTER the keys above (see docblock) so InnoDB reuses them
            // rather than building duplicate FK indexes.
            $table->foreign('masjid_id')->references('id')->on('masjids')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assigned_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_staff');
    }
};
