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
 * IT ASKS BEFORE IT REWRITES ANYTHING. The rows it would rewrite cannot be told
 * apart from the rows the feature is made of: once the roles switch has been on, a
 * lunch login's `teacher` row at a school is a DELIBERATE grant, and rewriting it
 * would make her lunch staff AT THE SCHOOL (her teacher area gone, the lunch area
 * opened where no office gave it). The switch being on is not a good enough guard:
 * turning it off is the documented way back, and a migration that is rolled back
 * and run again in that state (a staging box, `migrate:refresh`, a roll-forward
 * after the rows were put back) would flatten every one of them. So when it finds
 * such a row it STOPS, with their ids, and writes nothing, until the person
 * running it says which they are, in the environment of that one run:
 *
 *   NORMALISE_SCOPED_ROLES=rewrite   They were written by hand, before any office
 *                                    could give a second role (the FIRST release
 *                                    only). Each becomes the role of its login's
 *                                    kind, so it goes on meaning what it meant.
 *   NORMALISE_SCOPED_ROLES=keep      They are, or may be, deliberate. Nothing is
 *                                    written and the migration is recorded as run.
 *
 * On production, as the deploy runs it (bin/deploy):
 *   sudo -u www-data HOME=/tmp NORMALISE_SCOPED_ROLES=rewrite php artisan migrate --force
 * `php artisan staff:cross-kind-roles` lists the same rows beforehand, and the
 * release reads that list BEFORE the deploy (docs/multi-org-admin-rollout.md), so
 * the expected run finds nothing, asks nothing and writes nothing.
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
 * compares the roles in PHP, exactly, as kindFor() does. MySQL's collation would
 * call `Teacher` and `teacher ` the same as `teacher`, and the IN () below returns
 * such a row; the application never reads it as one of the two roles, so it means
 * "the login's kind decides" before and after, and it is left alone.
 *
 * While the roles switch is ON it does nothing at all and says so: a deploy to a
 * box where the feature is live must not stop on the rows the feature is made of.
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

    /** The environment variable that answers, for one run, what the rows found are. */
    private const ANSWER = 'NORMALISE_SCOPED_ROLES';

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

        // The role each row should carry, and the rows that carry the OTHER one.
        $rewrite = [];

        foreach ($rows as $row) {
            $role = self::ROLE_OF_KIND[$row->type] ?? null;

            if ($role !== null && $row->role !== $role && in_array($row->role, self::ROLE_OF_KIND, true)) {
                $rewrite[$role][] = (int) $row->id;
            }
        }

        if ($rewrite === []) {
            Log::info('normalise_scoped_roles_on_masjid_user: 0 rows rewritten (every teacher and lunch-staff row already carries the role of its login).');

            return;
        }

        $ids = array_merge(...array_values($rewrite));
        sort($ids);
        $count = count($ids);
        $answer = getenv(self::ANSWER);

        if ($answer === 'keep') {
            Log::warning('normalise_scoped_roles_on_masjid_user: '.$count.' row(s) give a teacher\'s or lunch-staff login the other kind\'s role and were KEPT as they are ('.self::ANSWER.'=keep).', [
                'masjid_user_ids_by_role_of_login_kind' => $rewrite,
            ]);

            return;
        }

        if ($answer !== 'rewrite') {
            // Before any write. The message carries ids only, never a name or an address.
            throw new RuntimeException(
                'normalise_scoped_roles_on_masjid_user stopped and wrote nothing: '.$count.' masjid_user row(s) give a teacher\'s or lunch-staff login '
                .'the OTHER kind\'s role (ids '.implode(', ', $ids).'; `php artisan staff:cross-kind-roles` lists them). '
                .'Rewriting a row an office wrote on purpose would move that person to the other area at that organisation, so say which they are and run migrate again: '
                .self::ANSWER.'=rewrite if every one was written by hand before any office could give a second role (the first release), '
                .self::ANSWER.'=keep if any of them is, or may be, a role an office gave. See docs/multi-org-admin-rollout.md.'
            );
        }

        foreach ($rewrite as $role => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('masjid_user')->whereIn('id', $chunk)->update(['role' => $role, 'updated_at' => now()]);
            }
        }

        Log::warning('normalise_scoped_roles_on_masjid_user: '.$count.' row(s) rewritten to the role of their login\'s kind ('.self::ANSWER.'=rewrite).', [
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
