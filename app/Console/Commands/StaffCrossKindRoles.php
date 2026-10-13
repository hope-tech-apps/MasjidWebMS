<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `staff:cross-kind-roles`: which logins hold a role of the OTHER scoped kind?
 *
 * One login may run Friday lunch at one organisation and teach at another. Such a
 * login has a `masjid_user` row whose role (`teacher` or `lunch-staff`) is not the
 * role of its `users.type`: a login created as lunch staff holding a `teacher` row,
 * or a login created as a Teacher holding a `lunch-staff` row. Those rows are what
 * the feature is made of, and they are the ONE thing that makes going back unsafe.
 *
 * Code from before the feature reads only `users.type`. To it, a lunch login's
 * `teacher` row at a school is a live row of a lunch login, so it binds the LUNCH
 * area at that school (the guides at once, the board wherever the school has Friday
 * lunch). So:
 *
 *   - Turning the feature OFF is the config switch (TENANCY_ROLES_PER_ORGANISATION),
 *     which is safe with these rows in place: the code that knows about them keeps
 *     each login to its own kind's area.
 *   - Rolling the CODE back while any of these rows exists is not. This command is
 *     the check to run first, and its `--json` output is the export to keep: remove
 *     the rows by query, roll back, and put them back on the way forward again
 *     (docs/multi-org-admin-rollout.md has the statements).
 *
 * It is also the check to run after anybody changes a `users.type` by hand: the row
 * now decides, so a hand re-type of a teacher or lunch-staff login needs its rows
 * looked at too.
 *
 * It prints ids and kinds, never a name or an address. The exit code is the answer a
 * script reads:
 *
 *   0  none: code from before the feature is safe to deploy
 *   3  at least one: it is not
 *
 * Anything else is the framework's own failure code (the table is missing, the
 * database cannot be reached): the caller must read it as "could not tell" and stop.
 * Read-only. It runs on a database from before the feature too.
 */
class StaffCrossKindRoles extends Command
{
    /** At least one such row exists. Not 1 or 2, which a crash or a usage error also answer. */
    public const ROWS_EXIST = 3;

    /** `users.type` => the scoped role that is NOT that kind's own. */
    private const OTHER_KINDS_ROLE = [
        'LunchStaff' => 'teacher',
        'Teacher' => 'lunch-staff',
    ];

    protected $signature = 'staff:cross-kind-roles {--json : Print the rows as JSON (the export to keep before removing them)}';

    protected $description = 'List masjid_user rows giving a teacher or lunch-staff login the other kind\'s role (exit 0: none, 3: some)';

    public function handle(): int
    {
        // The raw tables: a console run has no tenant bound, the question is about
        // every organisation, and archived logins and organisations count (a
        // restored one binds again).
        $rows = DB::table('masjid_user as mu')
            ->join('users as u', 'u.id', '=', 'mu.user_id')
            ->leftJoin('masjids as m', 'm.id', '=', 'mu.masjid_id')
            ->where(function ($query) {
                foreach (self::OTHER_KINDS_ROLE as $kind => $role) {
                    $query->orWhere(fn ($pair) => $pair->where('u.type', $kind)->where('mu.role', $role));
                }
            })
            ->orderBy('mu.user_id')
            ->orderBy('mu.masjid_id')
            ->get([
                'mu.id', 'mu.masjid_id', 'mu.user_id', 'mu.role', 'mu.is_default', 'mu.last_seen_at',
                'mu.created_at', 'mu.updated_at', 'u.type as login_kind', 'u.deleted_at as login_archived_at',
                'm.deleted_at as organisation_archived_at',
            ])
            // The collation may have matched a role or a kind that only LOOKS the
            // same (case, trailing spaces); the application compares exactly.
            ->filter(fn ($row): bool => (self::OTHER_KINDS_ROLE[$row->login_kind] ?? null) === $row->role)
            ->values();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows->all(), JSON_PRETTY_PRINT));
        } elseif ($rows->isEmpty()) {
            $this->line('0');
        } else {
            $this->table(
                ['masjid_user.id', 'masjid_id', 'user_id', 'users.type', 'role', 'default', 'login archived', 'organisation archived'],
                $rows->map(fn ($row): array => [
                    $row->id, $row->masjid_id, $row->user_id, $row->login_kind, $row->role,
                    $row->is_default ? 'yes' : 'no',
                    $row->login_archived_at === null ? 'no' : 'yes',
                    $row->organisation_archived_at === null ? 'no' : 'yes',
                ])->all()
            );
            $this->line((string) $rows->count());
        }

        return $rows->isEmpty() ? self::SUCCESS : self::ROWS_EXIST;
    }
}
