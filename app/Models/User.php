<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
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
    public const TYPE_ROLE_MAP = [
        'SuperAdmin' => 'super-admin',
        'MasjidAdmin' => 'masjid-admin',
        'User' => 'member',
    ];

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
        'password',
        'remember_token',
        // Never expose the raw TOTP secret in API payloads (the login/user
        // endpoints serialize the whole User model).
        'two_factor_secret',
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
        ];
    }

    public function masjid()
    {
        return $this->hasOne(Masjid::class);
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
