<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One date a form response reserved from its form's list (settings.reservation;
 * Ramadan giving through forms, 2026-09-25). See the create_form_date_reservations_table
 * migration for why `holding_on` exists beside `reserved_on`.
 *
 * Nothing here is fillable: every write goes through App\Support\FormReservations,
 * under the form's row lock, because a reservation is money-adjacent state (a paid
 * sponsor who loses their evening is a refund).
 *
 * ## Scoping
 *
 * `BelongsToMasjid`: the admin board runs bound, so another masjid's reservations are
 * invisible there. The public submit and the checkout run UNBOUND, so
 * FormReservations always filters by form (and the form by the header's masjid) by
 * hand and sets masjid_id explicitly. Pinned by tests/Feature/FormDateReservationTest.php.
 */
class FormDateReservation extends Model
{
    use BelongsToMasjid;

    /** An unpaid card registration never paid, and another payer asked for its date. */
    public const RELEASED_LAPSED = 'lapsed';

    /** The registration was cancelled by an admin, and another payer asked for its date. */
    public const RELEASED_CANCELLED = 'cancelled';

    protected $guarded = ['*'];

    /*
     * reserved_on and holding_on are deliberately NOT cast. A `date` cast writes
     * 'Y-m-d H:i:s' on assignment, which SQLite stores as given, so a lookup by the
     * plain 'Y-m-d' every answer carries would never match on the suite's driver.
     * They are written and read as ISO date strings on both.
     */

    protected $casts = [
        'masjid_id' => 'integer',
        'form_id' => 'integer',
        'form_response_id' => 'integer',
        'held_until' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(FormResponse::class, 'form_response_id');
    }

    /** Still holding its date: not released. It may still have lapsed (lapsedAt()). */
    public function isHolding(): bool
    {
        return $this->holding_on !== null;
    }

    /** The reserved date as the ISO string the form's list and the answers use. */
    public function date(): string
    {
        return substr((string) $this->reserved_on, 0, 10);
    }

    /**
     * Whether this hold no longer protects its date at $at, so the next payer who asks
     * may release it: its registration was cancelled, or it is an unpaid card
     * registration whose page (and the grace after it) has run out.
     *
     * Paid, cash and office rows never lapse. A card row that is paid never lapses
     * whatever held_until says: the payment is the reservation.
     */
    public function yieldsTo(FormResponse $response, CarbonInterface $at): ?string
    {
        if ($response->isCancelled()) {
            return self::RELEASED_CANCELLED;
        }

        if ($response->isPaid() || $this->held_until === null) {
            return null;
        }

        return $response->payment_method === FormResponse::METHOD_ONLINE && $this->held_until->lessThanOrEqualTo($at)
            ? self::RELEASED_LAPSED
            : null;
    }
}
