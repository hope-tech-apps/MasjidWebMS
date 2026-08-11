<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Offering — one registerable thing: an event, a program, an admission, a
 * membership, an appointment (docs/t006-registration-billing-design.md).
 * One noun for every vertical; `kind` is presentation, never authorization,
 * and verticals stay configuration.
 *
 * Tenant-scoped: BelongsToMasjid supplies the masjid_id global scope, the
 * server-derived creating hook, and the masjid() relationship. masjid_id stays
 * fillable so system/super code can set it while UNBOUND; a bound tenant always
 * overrides it. See .claude/rules/tenant-scoping.md.
 *
 * Intake is a normal Form (forms are the only intake machinery); the roster
 * target is a normal Group (groups are the only people-structure). This model
 * adds no machinery of its own on top of either.
 */
class Offering extends Model
{
    use HasFactory, SoftDeletes, BelongsToMasjid;

    /**
     * What KIND of registerable thing this is — a presentation discriminator,
     * never an authorization check. PHP constants rather than a DB enum,
     * exactly like Masjid::ORG_TYPES and Group::KINDS: adding a kind must not
     * mean ALTER TABLE on a live table (.claude/rules/migrations.md).
     * `appointment` reserves the vocabulary for the deferred offering_slots.
     */
    public const KIND_EVENT = 'event';
    public const KIND_PROGRAM = 'program';
    public const KIND_ADMISSION = 'admission';
    public const KIND_MEMBERSHIP = 'membership';
    public const KIND_APPOINTMENT = 'appointment';

    public const KINDS = [
        self::KIND_EVENT,
        self::KIND_PROGRAM,
        self::KIND_ADMISSION,
        self::KIND_MEMBERSHIP,
        self::KIND_APPOINTMENT,
    ];

    protected $fillable = [
        'masjid_id',
        'kind',
        'name',
        'slug',
        'intake_form_id',
        'group_id',
        'capacity',
        'opens_at',
        'closes_at',
        'is_active',
        'settings',
    ];

    /**
     * `registration_count` is the denormalised seat counter, maintained ONLY
     * inside T-006b's lockForUpdate registration transaction and the
     * seat-release paths — an admin editing an offering must never be able to
     * rewrite it. Mirrors Form::$guarded on response_count.
     */
    protected $guarded = ['registration_count'];

    protected $attributes = [
        'kind' => self::KIND_EVENT,
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'registration_count' => 'integer',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * The stored kind, degraded to `event` when unrecognized — the same
     * defensive read as Masjid::orgType() and Group::kind(). Kind is
     * presentation only, so an unknown value degrades to the plainest
     * presentation rather than ever granting kind-specific behaviour.
     */
    public function kind(): string
    {
        $kind = $this->attributes['kind'] ?? null;

        return in_array($kind, self::KINDS, true) ? $kind : self::KIND_EVENT;
    }

    /** The form whose schema validates every registration's intake answers. */
    public function intakeForm(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'intake_form_id');
    }

    /** Where confirmed registrants are materialised as group_memberships. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function feePlans(): HasMany
    {
        return $this->hasMany(FeePlan::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }

    // ------------------------------------------------------------- acceptance

    /**
     * Whether the registration window is open at $at (forms convention: a null
     * bound is unbounded on that side).
     */
    public function isWithinWindow(?CarbonInterface $at = null): bool
    {
        $at = $at ?: now();

        if ($this->opens_at && $at->lt($this->opens_at)) {
            return false;
        }

        if ($this->closes_at && $at->gt($this->closes_at)) {
            return false;
        }

        return true;
    }

    /**
     * Null capacity means unlimited. NOTE: an unlocked read — the authoritative
     * check is T-006b's lockForUpdate re-check inside the registration
     * transaction; this is for display and pre-flight only.
     */
    public function isAtCapacity(): bool
    {
        return $this->capacity !== null && $this->registration_count >= $this->capacity;
    }
}
