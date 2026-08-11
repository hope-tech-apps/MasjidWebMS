<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AppointmentRequestNote — one internal staff note on one appointment request
 * (PLAN T-021). Working notes about a person's health contact ("called back,
 * needs an interpreter"), so `body` is encrypted at rest and, like the parent
 * request's PII, must NEVER be logged — no Log::* call may receive it.
 *
 * Notes surface ONLY on the admin show endpoint behind `permission:view
 * contacts`; there is no public payload that carries one.
 *
 * Tenant-scoped by a denormalised masjid_id (same reasoning as
 * group_memberships): BelongsToMasjid scopes note queries without joining
 * through appointment_requests. See .claude/rules/tenant-scoping.md.
 */
class AppointmentRequestNote extends Model
{
    use BelongsToMasjid;

    protected $fillable = [
        'masjid_id',
        'appointment_request_id',
        'user_id',
        'body',
    ];

    protected function casts(): array
    {
        return [
            // Ciphertext at rest — see the class docblock.
            'body' => 'encrypted',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AppointmentRequest::class, 'appointment_request_id');
    }

    /** The staff member who wrote it; null once that account is deleted. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
