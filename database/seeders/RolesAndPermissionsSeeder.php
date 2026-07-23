<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the additive Spatie authorization layer for the CRM and BRIDGES it to
 * the legacy `users.type` enum. Fully idempotent (firstOrCreate + syncPermissions),
 * so it is safe to re-run on every deploy.
 *
 * This does NOT migrate away from `users.type` — that column stays the source of
 * truth for the `admin`/`super` middleware and all existing checks. The roles
 * here only mirror it so the NEW CRM endpoints can authorize via granular
 * permissions. See .claude/rules/auth-permissions.md.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** Granular CRM permissions (all masjid-scoped by the tenant guardrail). */
    private const PERMISSIONS = [
        'view contacts',
        'manage contacts',
        'view donations',
        'manage funds',
        'view donor pii',
        'manage donations',
        'view properties',
        'manage properties',
    ];

    public function run(): void
    {
        // Start from a clean cache so freshly-created rows are visible to any
        // permission check that runs later in the same process (e.g. tests).
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => $guard]);
        $masjidAdmin = Role::firstOrCreate(['name' => 'masjid-admin', 'guard_name' => $guard]);
        $member = Role::firstOrCreate(['name' => 'member', 'guard_name' => $guard]);

        // super-admin: everything.
        $superAdmin->syncPermissions(Permission::all());

        // masjid-admin: the full masjid-scoped CRM set. These admins already
        // reach the CRM screens today, so they must keep every CRM permission —
        // this is what preserves their current access.
        $masjidAdmin->syncPermissions(self::PERMISSIONS);

        // member: no CRM permissions by default.
        $member->syncPermissions([]);

        // Backfill existing users: mirror each one's `type` onto its bridged role
        // (SuperAdmin -> super-admin, MasjidAdmin -> masjid-admin, User -> member).
        User::withoutGlobalScopes()->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $user->syncRoleFromType();
            }
        });

        // Reset the cache again so the backfilled assignments are picked up.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
