<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, InteractsWithMedia, SoftDeletes, SearchableTrait;

    /**
     * Bridge between the legacy `users.type` enum and the additive Spatie roles.
     *
     * `type` REMAINS the source of truth for the existing `admin`/`super`
     * middleware and every pre-existing `type` check — nothing about that
     * changes. This map only mirrors `type` onto a Spatie role so the NEW CRM
     * endpoints can authorize via granular permissions. See
     * .claude/rules/auth-permissions.md and syncRoleFromType() below.
     */
    /**
     * A staff login that can reach the Jummah-lunch board and nothing else.
     *
     * Deliberately NOT one of UserAdminMiddleware::ADMIN_TYPES. The value is a
     * constant because it is compared in four places (the gate, the tenant
     * resolver branch, the provisioning controller, the SPA payload) and a typo
     * in any of them fails OPEN in the direction that matters least — it would
     * simply lock the user out — but a typo in the ADMIN list would not.
     */
    public const TYPE_LUNCH_STAFF = 'LunchStaff';

    public const TYPE_ROLE_MAP = [
        'SuperAdmin' => 'super-admin',
        'MasjidAdmin' => 'masjid-admin',
        'User' => 'member',
        // A school staff login scoped to the classes they lead. Bridged to a
        // PERMISSION-LESS 'teacher' role (like 'member'): a teacher's authority
        // is per-class, decided by group_staff via GroupAudience, never by a
        // global CRM permission — so this must not grant one. See group_staff.
        'Teacher' => 'teacher',
        // A login scoped to ONE module — the Jummah-lunch board — and nothing
        // else. Bridged to a PERMISSION-LESS 'lunch-staff' role for the same
        // reason as 'teacher': their authority is the realm they can reach
        // (routes/lunch.php), not a masjid-wide grant. Permission::count()
        // stays 8.
        self::TYPE_LUNCH_STAFF => 'lunch-staff',
    ];

    /**
     * The spatie guard this model's roles and permissions are registered under.
     *
     * This states explicitly what was previously being INFERRED — and inferred
     * correctly only by accident. Spatie derives a model's guard name from
     * `Spatie\Permission\Guard::getDefaultName()`, which prefers
     * `config('auth.defaults.guard')` whenever that guard also appears in the
     * list of `auth.guards` entries whose provider model is this class.
     *
     * The trap: `AuthManager::shouldUse()` REWRITES `auth.defaults.guard` in the
     * config repository at runtime, and both `auth:sanctum` and
     * `Sanctum::actingAs()` call it. So inside any authenticated admin request
     * the "default guard" is literally `sanctum`. Before T-015a pinned
     * `auth.guards.sanctum`, `sanctum` was not a declared guard, so it could
     * never match and resolution fell through to `web` — which is where
     * RolesAndPermissionsSeeder registers all 8 permissions. Declaring the
     * sanctum guard made `sanctum` matchable, and every `permission:`-gated CRM
     * route began 403ing with "There is no permission named `view contacts` for
     * guard `sanctum`".
     *
     * Pinning it here restores exactly the previous resolution (`web`, in every
     * context — console, web session and token request alike) and makes it
     * independent of how many guards point at this model, which the family guard
     * in T-015c will add more of. Keep it in step with the seeder's guard.
     */
    protected $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'type',
        'password'
    ];

    protected $searchableFields = ['name', 'email', 'phone'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'has_timetable_records',
        'password',
        'remember_token',
        // Never expose the raw TOTP secret in API payloads (the login/user
        // endpoints serialize the whole User model).
        'two_factor_secret',
        // The recovery codes are shown EXACTLY ONCE, in the response to the
        // request that generates them, and are never carried by an ordinary
        // payload. `/api/admin/user` runs on every page load and its body ends
        // up in browser caches, proxy logs and support screenshots; a set of
        // codes that each replace the second factor does not belong in it.
        // TwoFactorTest::recovery_codes_are_never_present_in_a_user_payload
        // pins this — the whole feature is only as private as this line.
        'two_factor_recovery_codes',
        // Derived from a live code (keyed HMAC). Nothing outside the replay
        // check has any use for it, and it is credential material.
        'two_factor_last_code_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // At rest the TOTP secret is ciphertext; reading the attribute
            // transparently decrypts it. Requires APP_KEY (already set).
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            // Ciphertext at rest, a plain array in PHP. Encrypted rather than
            // hashed on purpose — the user must be able to LOOK at these again
            // after enrollment, which is what makes them a recovery path rather
            // than a one-shot printout. The migration docblock argues it in
            // full; keep this cast and the $hidden entry above together, and
            // note that removing the cast is silent (nothing else notices), so
            // TwoFactorTest asserts on the raw column.
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_last_used_at' => 'datetime',
            'two_factor_locked_until' => 'datetime',
        ];
    }

    public function masjid()
    {
        return $this->hasOne(Masjid::class);
    }

    /**
     * Every organisation this user holds a membership in (`masjid_user`).
     *
     * The pivot S3's resolver decides the tenant from — one human, many org
     * affiliations, which `masjid()` above (a `hasOne`) cannot express.
     *
     * **`masjid()` is untouched, and still decides which of these counts.** S3
     * ships behind the one-membership gate (`config/tenancy.php`): while it is
     * shut, `App\Support\TenantResolver` treats only the membership naming the
     * masjid this user OWNS as a grant, so the binding is identical to before
     * the pivot existed. At most one of these rows may carry `is_default = 1`,
     * enforced by a unique index rather than by convention — see
     * `create_masjid_user_table`.
     *
     * `hasMany(MasjidUser)` rather than `belongsToMany(Masjid)` on purpose: the
     * design's resolver takes the MEMBERSHIP itself
     * (`TenantContext::setFromMembership(MasjidUser)`), so the row — with its
     * `role` and `is_default` — has to be the thing that is returned, not a masjid
     * with a pivot bag hanging off it.
     */
    public function memberships()
    {
        return $this->hasMany(MasjidUser::class);
    }

    /**
     * Whether this login holds a LIVE membership in an organisation other than
     * $masjidId: the fact that makes it a SHARED person.
     *
     * The `users` row (name, phone, password, sessions) is global, so anything
     * one school does to it lands in every school the person belongs to. A school
     * office must therefore treat a login that is also somebody else's teacher as
     * read-only where it is global: it cannot rewrite their name or phone, and
     * cannot mint a set-password link whose completion ends their sessions
     * elsewhere. (It may SEE the phone: the owner decided, 2026-09-29, that every
     * school that has the teacher can.) Every one of those guards asks THIS
     * question, so they cannot disagree about who is shared.
     *
     * `whereHas('masjid')` drops an organisation that has been trashed, exactly as
     * TenantResolver does: a membership in an archived school shares nothing today.
     */
    public function belongsOutside(int $masjidId): bool
    {
        return $this->memberships()
            ->where('masjid_id', '!=', $masjidId)
            ->whereHas('masjid')
            ->exists();
    }

    /**
     * Match a login by email, however the address was capitalised when it was stored.
     *
     * On MySQL and MariaDB `users.email` is utf8mb4_unicode_ci, which already compares
     * case-insensitively, so a plain equality is right AND is what lets the unique index
     * answer it. Wrapping the column in LOWER() there would make the server scan the
     * whole table, and under a locking read that means locking every row it scans.
     * SQLite (the suite) compares case-sensitively, so it needs the LOWER().
     *
     * THIS RETURNS CANDIDATES, NOT AN IDENTITY. That same collation compares accents
     * and expansions as equal too (`é` = `e`, `ß` = `ss`), so on MySQL the plain
     * equality also returns `sara@gmaíl.com` for `sara@gmail.com`. A caller that
     * acts on the row it finds (attaches it, restores it, trusts it) must confirm
     * it with ContactIdentity::sameAddress($user->email, $email) first, as
     * TeachersController::createOrAttach does.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     */
    public function scopeWhereEmailIs($query, string $email, ?string $driver = null)
    {
        $driver ??= $query->getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $query->where('email', $email);
        }

        return $query->whereRaw('LOWER(email) = ?', [strtolower($email)]);
    }

    /**
     * True for a school-teacher staff login (users.type = 'Teacher').
     *
     * The one place a teacher-specific code path may branch. `type` stays the
     * source of truth exactly as it is for SuperAdmin/MasjidAdmin; this is a
     * named read of it, not a new authority. Reused authorization in the shared
     * admin controllers guards its teacher branch behind this so the
     * MasjidAdmin/SuperAdmin path stays byte-identical.
     */
    public function isTeacher(): bool
    {
        return $this->type === 'Teacher';
    }

    /**
     * The classes this user leads (`group_staff`).
     *
     * This — NOT the legacy Contact `leader` membership — is the authoritative
     * teacher↔class link for a login. `GroupAudience` reads it to decide teacher
     * standing, and `Group::scopeLedBy()` filters "only my classes" through it.
     * A group_staff row is written with an EXPLICIT masjid_id, and its
     * assigned_by_user_id comes from GroupStaff's creating hook, never the caller
     * (attach() drops non-fillable extras); see GroupStaff and TeachersController.
     */
    public function groupsLed(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_staff')
            ->using(GroupStaff::class)
            ->withPivot(['role', 'assigned_by_user_id', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * True once the user has CONFIRMED TOTP enrollment. This — and only this —
     * is what makes the login flow require a 2FA code. Users who never enrolled
     * return false and log in exactly as before (no extra step, no lockout).
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Mirror the legacy `users.type` onto its bridged Spatie role, keeping the
     * two in sync going forward. Called by App\Observers\UserObserver on every
     * save and by the RolesAndPermissionsSeeder backfill.
     *
     * Deliberately defensive: it NEVER throws out to the caller, so a user
     * write can never be broken by the role bridge (e.g. on a fresh install
     * before the roles/permissions seeder has run, or if the Spatie tables are
     * not migrated yet). `type` stays authoritative regardless.
     */
    public function syncRoleFromType(): void
    {
        $roleName = self::TYPE_ROLE_MAP[$this->type] ?? null;

        if ($roleName === null) {
            return;
        }

        try {
            // Skip until the role exists (seeder not run yet) so we don't throw
            // RoleDoesNotExist during an ordinary user save.
            if (! Role::where('name', $roleName)->exists()) {
                return;
            }

            if (! $this->hasRole($roleName)) {
                $this->syncRoles([$roleName]);
            }
        } catch (\Throwable $e) {
            // The bridge is best-effort; the legacy `type` remains the source of
            // truth for existing authorization, so log and move on.
            Log::warning('syncRoleFromType skipped for user '.$this->getKey().': '.$e->getMessage());
        }
    }

    public function avatar()
    {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'avatars')
            ->orderBy('created_at', 'desc')
            ->latest();
    }
}
