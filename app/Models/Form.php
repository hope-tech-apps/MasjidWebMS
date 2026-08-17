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
     * A slot may name ONE field, or a LIST of fields whose values are joined with a
     * space — which is how a form that asks for first and last name separately still
     * produces a single searchable "Amal Yusuf" in the responses list.
     *
     * @return array{name: string|array|null, email: string|array|null, phone: string|array|null}
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

        // The cut-off is a CALENDAR DATE, so it has to be read in the masjid's own
        // timezone. config('app.timezone') is UTC, and a bare now()->toDateString()
        // therefore rolls over at 8:00 PM Eastern — an "early bird ends tonight" price
        // would quietly step up four hours early and charge the standard rate to anyone
        // registering that evening. Same rule as the giving dashboard's day buckets:
        // money boundaries belong to the masjid's clock, never the server's.
        $tz = $this->masjid?->timezone ?: config('app.timezone');
        $today = ($at ?: now())->copy()->setTimezone($tz)->toDateString();

        foreach ($tiers as $tier) {
            if (! is_array($tier) || ! isset($tier['amount'])) {
                continue;
            }

            // ABSENT, null, or BLANK: this tier has NO cut-off and is the
            // open-ended final price. All three are one statement, spelled three
            // ways — `database/forms/camp-2026.json` omits the key, the builder
            // sends a cleared box, and every HTTP door turns that cleared box
            // into null before any rule sees it (`TrimStrings` +
            // `ConvertEmptyStringsToNull`, global in bootstrap/app.php).
            //
            // The two BLANK spellings used to do opposite things here. Measured,
            // one tier schedule, three days:
            //
            //   until ''      2026-08-01 Early bird/100   2026-08-20 Early bird/100
            //                 2026-09-20 Early bird/100   <- treated as "no cut-off"
            //   until '   '   2026-08-01 Standard/120     <- SKIPPED, price steps up
            //                 2026-09-20 Day of camp/140
            //
            // Same blank, same document, two prices — and only rows written by
            // `form:import` could carry either, since the API stores null for
            // both. `TierCutoff::normalise()` is now the write-side statement of
            // this and `ImportFormCommand` applies it, so no new row can carry a
            // blank; the `trim()` below is the read half, for the rows that
            // already do. It is deliberately NOT the "unreadable steps up" rule:
            // a blank is not a date somebody got wrong, it is a date somebody did
            // not give, and this system has always had exactly one meaning for
            // that.
            $until = $tier['until'] ?? null;

            if (is_string($until)) {
                $until = trim($until);
            }

            if ($until === null || $until === '') {
                return $tier;
            }

            $until = self::normaliseCutoff($until);

            // A cut-off nothing can read is NOT in force. Skipping steps UP to
            // the next tier, which is visible on the page and complainable;
            // treating an unreadable date as "not yet passed" is the silent
            // under-charge below wearing a different hat.
            if ($until === null) {
                continue;
            }

            // Both sides are now provably zero-padded ISO yyyy-mm-dd, which is
            // the precondition that makes a lexical comparison correct. It used
            // to be assumed rather than established — see normaliseCutoff().
            if ($today <= $until) {
                return $tier;
            }
        }

        // Every dated tier has passed and none was open-ended — the last one stands.
        $last = end($tiers);

        return is_array($last) && isset($last['amount']) ? $last : null;
    }

    /**
     * A tier cut-off as a zero-padded `Y-m-d` string, or null if it is not a
     * calendar date at all.
     *
     * ## Why this exists
     *
     * `resolveTier()` compares `$today <= $until` as STRINGS, and the old
     * comment defended that as "safe and portable: both sides are ISO
     * yyyy-mm-dd". The left side always is — `toDateString()` pads. The right
     * side is whatever somebody typed, and `ImportFormCommand` accepted a form
     * whose `settings` were never validated, so `2026-8-14` landed in the column
     * with exit code 0 while the admin API answered 422 "must match the format
     * Y-m-d" for the identical payload. Under string comparison:
     *
     *     '2026-09-10' <= '2026-8-14'   is TRUE     ('0' < '8' at index 5)
     *
     * so the early-bird tier never expired. Measured on the imported camp form:
     * August 16 quoted $100 instead of $120, September 10 — after the camp had
     * finished — still quoted $100 instead of $140, and the tier only cleared at
     * the year rollover. $40 per attendee, for four months.
     *
     * The write path refuses an unpadded cut-off on every door —
     * `App\Rules\TierCutoff`, applied through `StoreFormRequest::settingsRules()`,
     * which the builder, the PATCH and `ImportFormCommand` all use. This is the
     * read half: rows already written carry the bad shape, and a comparison whose
     * correctness rests on a precondition nothing enforced is the defect
     * independently of who wrote the row.
     *
     * Deliberately LOOSER than the write rule in exactly one way: this accepts
     * `2026-8-14` and pads it, while the doors refuse it. That asymmetry is the
     * point — the doors stop new bad rows, this repairs the reading of the old
     * ones.
     *
     * BLANK does not reach here: `resolveTier()` answers it above, as "no
     * cut-off", which is what every write door stores for it.
     *
     * Deliberately NOT `strtotime()` / `Carbon::parse()`: both accept relative
     * strings ("next friday", "+1 month"), which would make a typo in a fee
     * schedule silently mean something. A cut-off is a literal calendar date or
     * it is nothing.
     */
    private static function normaliseCutoff(mixed $until): ?string
    {
        if (! is_string($until) && ! is_int($until)) {
            return null;
        }

        if (! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', trim((string) $until), $m)) {
            return null;
        }

        [, $year, $month, $day] = $m;

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
