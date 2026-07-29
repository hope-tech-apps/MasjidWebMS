<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sign-up form belonging to one masjid — event RSVP, membership application,
 * camp registration.
 *
 * A form is independent of the pages it appears on. A `form` section carries only
 * `content.form_id`; see the create_forms_table migration for why the schema does not
 * live in the section.
 */
class Form extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'masjid_id',
        'slug',
        'name',
        'description',
        'schema',
        'settings',
        'is_active',
        'opens_at',
        'closes_at',
        'capacity',
    ];

    protected $casts = [
        'schema' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'capacity' => 'integer',
        'response_count' => 'integer',
    ];

    /**
     * `response_count` is maintained by FormResponse's model events, not by mass
     * assignment — an admin editing a form must never be able to rewrite the count.
     */
    protected $guarded = ['response_count'];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    public function responses()
    {
        return $this->hasMany(FormResponse::class);
    }

    /** Only forms an admin has switched on. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ---------------------------------------------------------------- acceptance

    /**
     * Whether the registration window is currently open.
     *
     * A null bound is unbounded on that side, so a form with neither bound set is
     * always within its window. Comparison is against the app timezone via now().
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

    /** Null capacity means unlimited. */
    public function isAtCapacity(): bool
    {
        return $this->capacity !== null && $this->response_count >= $this->capacity;
    }

    /**
     * The single question the public submit endpoint asks before accepting anything.
     */
    public function acceptsSubmissions(?CarbonInterface $at = null): bool
    {
        return $this->is_active
            && $this->isWithinWindow($at)
            && ! $this->isAtCapacity();
    }

    /**
     * Why a submission is being refused, for the public renderer's closed-state banner.
     * Null when the form is accepting.
     */
    public function closedReason(?CarbonInterface $at = null): ?string
    {
        $at = $at ?: now();

        if (! $this->is_active) {
            return 'This form is not currently accepting responses.';
        }

        if ($this->opens_at && $at->lt($this->opens_at)) {
            return 'Registration opens on ' . $this->opens_at->format('F j, Y') . '.';
        }

        if ($this->closes_at && $at->gt($this->closes_at)) {
            return 'Registration closed on ' . $this->closes_at->format('F j, Y') . '.';
        }

        if ($this->isAtCapacity()) {
            return 'This form has reached capacity.';
        }

        return null;
    }

    // ------------------------------------------------------------------- schema

    /** @return array<int,array<string,mixed>> The schema's sections, always an array. */
    public function sections(): array
    {
        $sections = $this->schema['sections'] ?? [];

        return is_array($sections) ? $sections : [];
    }

    /** The section marked repeatable, if any (camp attendees, RSVP guests). */
    public function repeatableSection(): ?array
    {
        foreach ($this->sections() as $section) {
            if (! empty($section['repeatable'])) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Which schema fields feed the denormalised respondent_* columns.
     * Declared by the builder so search and sort do not have to guess.
     *
     * @return array{name: ?string, email: ?string, phone: ?string}
     */
    public function identityMap(): array
    {
        $identity = $this->settings['identity'] ?? [];

        return [
            'name' => $identity['name'] ?? null,
            'email' => $identity['email'] ?? null,
            'phone' => $identity['phone'] ?? null,
        ];
    }

    /**
     * The fee rule as it applies RIGHT NOW, or null when the form does not charge.
     *
     * `perEntryOfSection` names a repeatable section — the total is that section's
     * entry count times the amount. Without it, the amount is a flat fee.
     *
     * `tiers` express date-stepped pricing (early bird → standard → day-of). Each tier
     * has an `amount` and an optional `until` date, INCLUSIVE: the first tier whose
     * `until` has not yet passed wins, and a tier with no `until` is the final price. A
     * form with no tiers behaves exactly as before.
     *
     * The resolved amount is what gets stored on a response at submission time, so a
     * later price step never restates what somebody already agreed to pay.
     *
     * @return array{amount: float, currency: string, perEntryOfSection: ?string, tiers: array, currentTier: ?array}|null
     */
    public function feeRule(?CarbonInterface $at = null): ?array
    {
        $fee = $this->settings['fee'] ?? null;

        if (! is_array($fee)) {
            return null;
        }

        $tiers = is_array($fee['tiers'] ?? null) ? array_values($fee['tiers']) : [];

        if ($tiers === [] && ! isset($fee['amount'])) {
            return null;
        }

        $currentTier = $this->resolveTier($tiers, $at);

        $amount = $currentTier['amount']
            ?? $fee['amount']
            ?? null;

        if ($amount === null) {
            return null;
        }

        return [
            'amount' => (float) $amount,
            'currency' => $fee['currency'] ?? 'USD',
            'perEntryOfSection' => $fee['perEntryOfSection'] ?? null,
            'tiers' => $tiers,
            'currentTier' => $currentTier,
        ];
    }

    /**
     * The tier in force on a given day: the first whose inclusive `until` has not passed,
     * otherwise the first tier without an `until` (the final, open-ended price).
     *
     * @param  array<int,array<string,mixed>>  $tiers
     */
    private function resolveTier(array $tiers, ?CarbonInterface $at = null): ?array
    {
        if ($tiers === []) {
            return null;
        }

        $today = ($at ?: now())->toDateString();

        foreach ($tiers as $tier) {
            if (! is_array($tier) || ! isset($tier['amount'])) {
                continue;
            }

            $until = $tier['until'] ?? null;

            if ($until === null || $until === '') {
                return $tier;
            }

            // String comparison is safe and portable: both sides are ISO yyyy-mm-dd.
            if ($today <= (string) $until) {
                return $tier;
            }
        }

        // Every dated tier has passed and none was open-ended — the last one stands.
        $last = end($tiers);

        return is_array($last) && isset($last['amount']) ? $last : null;
    }
}
