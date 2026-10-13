<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Make every teacher's and lunch-staff login's `masjid_user.role` agree with its
 * `users.type`, ONCE, before the role starts to mean something.
 *
 * Until now `masjid_user.role` was advisory: every decision read `users.type`. From
 * this release the role of a row decides what a teacher's or lunch-staff login IS at
 * that organisation (App\Models\MasjidUser::kindFor): a `teacher` row makes its
 * login a teacher there and a `lunch-staff` row makes it lunch staff there, whichever
 * of the two the login was created as. That is how one person runs Friday lunch at
 * one organisation and teaches at another with one login.
 *
 * So a row written while the role meant nothing must not start meaning something
 * new. The only rows that would are those of a login of one of the two kinds whose
 * role names the OTHER kind's role. Each of them means "the login's kind decides"
 * today; this rewrites it to the role of the login's kind, which says exactly that.
 * Every other row is left alone and keeps its meaning without help: a role that is
 * not one of the two (`masjid-admin`, `admin`, `owner`, `member`) still means "the
 * login's kind decides", and an administrator's, a member's or a SuperAdmin's row is
 * never re-read whatever it says.
 *
 * NO ROW IS EXPECTED TO CHANGE. Every door has always written a matching pair
 * (TeachersController, LunchStaffController, TeamController, which moves the type
 * and the role together). A row found here was written by hand.
 *
 * Shaped for a live table:
 *  - it READS first, with a plain join and no locking read, and when there is
 *    nothing to rewrite (the expected case) it writes nothing at all;
 *  - what it does rewrite, it addresses by primary key. (An
 *    `UPDATE ... WHERE user_id IN (SELECT id FROM users WHERE type = ?)` would set
 *    shared locks on every `users` row the subquery reads, and `users.type` has no
 *    index, so it would lock the whole login table for the length of the statement.)
 *  - no DDL. `role` is in no index and is not part of the VIRTUAL `default_key`
 *    column (create_masjid_user_table), so nothing is rebuilt.
 *
 * Both drivers: the query builder only, so MySQL gets backticked identifiers and
 * bound values (ANSI_QUOTES is not in play) and SQLite the same statements. It
 * compares the roles in PHP, exactly, as kindFor() does: MySQL's collation would
 * call `Teacher` and `teacher ` the same as `teacher`, and a row the IN () below
 * returns for that reason is normalised to the exact spelling too.
 *
 * IT MUST NOT RUN ONCE A LOGIN HOLDS TWO ROLES ON PURPOSE. With the roles switch on
 * (config/tenancy.php) a cross-kind row is a deliberate grant, and this would turn
 * it back into the home kind's role: a lunch login's teacher row would become a
 * lunch row at the school. It runs in the deploy that ships the feature, with the
 * switch off, which is how the feature ships. If the switch is found on, it does
 * nothing and says so; `php artisan staff:cross-kind-roles` lists such rows.
 *
 * The role names are written out here, not read from the model: a migration is a
 * record of what was done on the day, and must not follow a constant that changes.
 */
return new class extends Migration
{
    /** `users.type` => the role every door writes for a login of that kind. */
    private const ROLE_OF_KIND = [
        'Teacher' => 'teacher',
        'LunchStaff' => 'lunch-staff',
    ];

    public function up(): void
    {
        if ((bool) config('tenancy.roles_per_organisation', false)) {
            Log::warning('normalise_scoped_roles_on_masjid_user: skipped, because tenancy.roles_per_organisation is on. '
                .'A row whose role and login kind differ is a deliberate grant now and must not be rewritten.');

            return;
        }

        $rows = DB::table('masjid_user as mu')
            ->join('users as u', 'u.id', '=', 'mu.user_id')
            ->whereIn('u.type', array_keys(self::ROLE_OF_KIND))
            ->whereIn('mu.role', array_values(self::ROLE_OF_KIND))
            ->orderBy('mu.id')
            ->get(['mu.id', 'mu.role', 'u.type']);

        // The role each row should carry, and the rows that do not carry it.
        $rewrite = [];

        foreach ($rows as $row) {
            $role = self::ROLE_OF_KIND[$row->type] ?? null;

            if ($role !== null && $row->role !== $role) {
                $rewrite[$role][] = (int) $row->id;
            }
        }

        if ($rewrite === []) {
            Log::info('normalise_scoped_roles_on_masjid_user: 0 rows rewritten (every teacher and lunch-staff row already carries the role of its login).');

            return;
        }

        foreach ($rewrite as $role => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('masjid_user')->whereIn('id', $chunk)->update(['role' => $role, 'updated_at' => now()]);
            }
        }

        Log::warning('normalise_scoped_roles_on_masjid_user: '.array_sum(array_map('count', $rewrite)).' row(s) rewritten to the role of their login\'s kind.', [
            'masjid_user_ids_by_new_role' => $rewrite,
        ]);
    }

    /**
     * Nothing to undo. The code before this release never read `role`, so the
     * rewritten rows mean to it what they meant before; and the spelling they had is
     * not worth keeping (it is in the log line above for anybody who wants it).
     */
    public function down(): void
    {
    }
};
