<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AppointmentRequest — a Community-vertical intake request (PLAN T-021).
 *
 * The pilot is a free clinic replacing plaintext-Gmail appointment emails.
 * A visitor asks for an appointment on the public site; staff triage the queue
 * in the admin dashboard (contact -> schedule -> close).
 *
 * SENSITIVE AT REST — date_of_birth and reason are health-adjacent PII and use
 * the `encrypted` cast, so the DB (and any backup of it) holds ciphertext.
 * That also means: never query/filter on them, and NEVER log them — request
 * payloads carrying these fields must not reach Log::* on any path. Errors
 * flow through App\Support\Errors::publicMessage, which logs only exception
 * metadata, never input.
 *
 * Tenant-scoped: BelongsToMasjid supplies the masjid_id global scope, the
 * server-derived creating hook, and the masjid() relationship. masjid_id stays
 * fillable so the UNBOUND public endpoint can set it from the masjid-id header;
 * a bound tenant always overrides it. See .claude/rules/tenant-scoping.md.
 */
class AppointmentRequest extends Model
{
    use HasFactory, BelongsToMasjid;

    /**
     * Triage statuses. PHP constants, NOT a DB enum — the same reasoning as
     * Masjid::ORG_TYPES and Group::KINDS: adding a status must never require
     * ALTER TABLE on a live table (.claude/rules/migrations.md). Validated at
     * the request boundary with Rule::in(...).
     */
    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_SCHEDULED,
        self::STATUS_CLOSED,
    ];

    /** Where a request came from. 'web' = the public V1 endpoint. */
    public const SOURCE_WEB = 'web';

    protected $fillable = [
        'masjid_id',
        'applicant_name',
        'phone',
        'email',
        'date_of_birth',
        'reason',
        'preferred_window',
        'status',
        'source',
        'ip_address',
        'user_agent',
    ];

    protected $attributes = [
        'status' => self::STATUS_NEW,
        'source' => self::SOURCE_WEB,
    ];

    protected function casts(): array
    {
        return [
            // Ciphertext at rest; decrypted transparently on access. A plain
            // `encrypted` (not encrypted:date) because Laravel has no combined
            // cast — the boundary enforces the Y-m-d shape instead.
            'date_of_birth' => 'encrypted',
            'reason' => 'encrypted',
        ];
    }

    /**
     * The stored status, degraded to `new` when unrecognized — the same
     * defensive read as Masjid::orgType() and Group::kind(). An unknown value
     * must never make a request behave as if someone had triaged it.
     */
    public function status(): string
    {
        $status = $this->attributes['status'] ?? null;

        return in_array($status, self::STATUSES, true) ? $status : self::STATUS_NEW;
    }

    public function notes(): HasMany
    {
        return $this->hasMany(AppointmentRequestNote::class);
    }

    protected static function booted(): void
    {
        // Deleting a request removes its notes THROUGH THE MODEL, not via the
        // FK cascade (which is only a backstop): a DB cascade fires no model
        // events, so any behaviour a note ever grows on deletion would be
        // silently skipped. Same pattern as FormResponseAttachment /
        // GroupMembership — see .claude/rules/private-uploads.md.
        static::deleting(function (self $request): void {
            $request->notes()->get()->each->delete();
        });
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
