<?php

namespace App\Models;

use App\Services\Stripe\FormChargeAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

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

    /** The per-staff cash codes issued on this form (FormStaffCode). */
    public function staffCodes()
    {
        return $this->hasMany(FormStaffCode::class);
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

    // ------------------------------------------------------------------ payment

    /**
     * The only group link a form hands out: a chat.whatsapp.com invite and
     * nothing else. One definition for every reader — whatsappUrl() checks it on
     * the way out, and the settings rules should check it on the way in. `\z`,
     * not `$`: a `$` also matches before a trailing newline.
     */
    public const WHATSAPP_URL_PATTERN = '#^https://chat\.whatsapp\.com/[A-Za-z0-9]{10,64}\z#';

    /** feeRule()['pricing'] on a form priced by how many entries it has (settings.fee.countTiers). */
    public const PRICING_COUNT = 'count';

    /**
     * feeRule()['pricing'] on a form priced by the answer to one choice question
     * (settings.fee.byChoice; Ramadan giving, 2026-09-25): iftar sponsorship levels.
     */
    public const PRICING_CHOICE = 'choice';

    /**
     * The most units one submission may be charged for by a quantity question
     * (settings.fee.perQuantityOf), whatever the question's own `max` says.
     *
     * Derivation: the same ceiling the count prices already put on a family size
     * (StoreFormRequest, 'settings.fee.countTiers.*.min' => max:1000). It exists so a
     * typo ("1000" people for "10") or a scripted post cannot open a page for an
     * amount nobody meant; Stripe's own $999,999.99 bound is still checked before any
     * page opens (FormResponseCheckoutService::refusal()). A form that needs more
     * than this per submission is not a donation form.
     */
    public const MAX_QUANTITY = 1000;

    /**
     * Whether any price on this form is above zero: the flat amount, any tier
     * whatever its date, or any price by number of entries.
     *
     * Deliberately not `feeRule() !== null`. A $0 fee asks for nothing, and "does
     * this form charge?" must not change its answer as the tiers step, or a
     * row's settled state (FormResponse::isSettled()) would flip overnight.
     *
     * The count prices are read here too (BISS, 2026-09-13). Without them a form
     * priced only by the number of children "charges nothing": takesOnlinePayment()
     * would be false and every registration free.
     */
    public function chargesFee(): bool
    {
        $fee = $this->settings['fee'] ?? null;

        if (! is_array($fee)) {
            return false;
        }

        $amounts = [$fee['amount'] ?? null];

        foreach (is_array($fee['tiers'] ?? null) ? $fee['tiers'] : [] as $tier) {
            $amounts[] = is_array($tier) ? ($tier['amount'] ?? null) : null;
        }

        foreach (is_array($fee['countTiers'] ?? null) ? $fee['countTiers'] : [] as $tier) {
            $amounts[] = is_array($tier) ? ($tier['amount'] ?? null) : null;
        }

        // Priced by the answer to a question (Ramadan giving, 2026-09-25): without these a
        // form whose only prices are its sponsorship levels would "charge nothing".
        $byChoice = is_array($fee['byChoice'] ?? null) ? $fee['byChoice'] : [];

        foreach (is_array($byChoice['prices'] ?? null) ? $byChoice['prices'] : [] as $price) {
            $amounts[] = is_array($price) ? ($price['amount'] ?? null) : null;
        }

        foreach ($amounts as $amount) {
            if (is_numeric($amount) && (float) $amount > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Card payments are switched on AND there is a price. The flag alone never
     * routes a submission to Stripe: with nothing to charge there is nothing to
     * take.
     */
    public function takesOnlinePayment(): bool
    {
        return $this->paymentFlag('online') && $this->chargesFee();
    }

    /**
     * Staff codes are switched on AND there is a price. A code settles an entry
     * as cash its holder owes at the list price; with no price there is nothing
     * to owe, and cash for $0 is not a settlement.
     */
    public function takesStaffCodes(): bool
    {
        return $this->paymentFlag('staffCodes') && $this->chargesFee();
    }

    /**
     * The optional "cover the card fee" checkbox: card payments only — cash has no card fee.
     *
     * NEVER true while the fee is required (requiresFeeCoverage()), even if both switches
     * were stored. A renderer that predates the required fee reads allowFeeCoverage as
     * "draw an unticked optional box"; publishing it true beside a required fee would show
     * $250.00 on the page while Stripe charges $257.78 (BISS critique, should_fix 1;
     * money review, 2026-09-14). The save refuses the pair; this is the read half.
     */
    public function allowsFeeCoverage(): bool
    {
        return $this->paymentFlag('allowFeeCoverage') && ! $this->requiresFeeCoverage() && $this->takesOnlinePayment();
    }

    /**
     * Every card payer covers the card fee, whatever the browser says
     * (settings.payment.requireFeeCoverage; BISS, 2026-09-13). Card payments only:
     * a staff-code entry and a registration paid at the office carry no card fee.
     */
    public function requiresFeeCoverage(): bool
    {
        return $this->paymentFlag('requireFeeCoverage') && $this->takesOnlinePayment();
    }

    /**
     * The family may choose to pay the office instead of a card
     * (settings.payment.officePayment; BISS, 2026-09-13), AND there is a price.
     * Their registration is written unpaid, owing the list price with no card
     * fee, and staff record the money when it arrives (Zelle, Cash App, Venmo,
     * cash, check). With nothing to charge there is nothing to owe the office.
     */
    public function takesOfficePayment(): bool
    {
        return $this->paymentFlag('officePayment') && $this->chargesFee();
    }

    /**
     * Whether any kind of payment is switched on and priced: card, staff codes or
     * the office. Every "money moves here" gate asks this one question — the replay
     * guard, the never-free quote and the save's paying-form rules — so an
     * office-only form can never slip under all three.
     */
    public function takesPayment(): bool
    {
        return $this->takesOnlinePayment() || $this->takesStaffCodes() || $this->takesOfficePayment();
    }

    /**
     * Card payment is on and the organisation can take a card RIGHT NOW: on its own
     * live Connect account, or on the parent's account a SuperAdmin linked it to
     * (App\Services\Stripe\FormChargeAccount; DECISIONS.md 2026-09-15). What the
     * payload calls `available`, and what a submission that did not say how it pays
     * is routed by.
     */
    public function canTakeCardNow(): bool
    {
        return $this->takesOnlinePayment() && FormChargeAccount::for($this->masjid) !== null;
    }

    /**
     * How to pay the office, in the office's own words (the Zelle and Cash App
     * handles, the office hours), or null. Typed by the organisation, never
     * hard-coded; shown on the page and in the "amount owed" email.
     */
    public function officeInstructions(): ?string
    {
        $payment = $this->settings['payment'] ?? null;
        $text = is_array($payment) ? ($payment['officeInstructions'] ?? null) : null;

        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }

    /**
     * Whether a staff member at the gate may record an entry: switched on and
     * not full. The registration WINDOW is skipped on purpose — walk-ups arrive
     * after online registration has closed — the way the lunch board ignores the
     * ordering window. Inactive and full still refuse.
     */
    public function acceptsStaffEntry(): bool
    {
        return $this->is_active && ! $this->isAtCapacity();
    }

    /**
     * The WhatsApp group link, or null. Re-checked against the pattern on the
     * way out because settings have more than one door (the builder, the PATCH,
     * form:import). Hand it out only once a response is settled; it never
     * belongs in the public page payload.
     */
    public function whatsappUrl(): ?string
    {
        $url = $this->settings['whatsappUrl'] ?? null;

        return is_string($url) && preg_match(self::WHATSAPP_URL_PATTERN, $url) === 1 ? $url : null;
    }

    /**
     * Whether this form has been set up to take payment at all: its settings carry a
     * `payment` block, whatever its switches say now.
     *
     * Only then do the admin screens show the money leg (the payment columns in both
     * CSVs, the payment badge). A fee form that never was, like Burlington's camp,
     * whose families pay elsewhere and are triaged "confirmed", keeps exactly the
     * exports it always had instead of every family reading "Unpaid". Not
     * takesOnlinePayment() or takesStaffCodes(): a festival form with both switched
     * off after the day is still reconciled from those columns.
     */
    public function hasPaymentSettings(): bool
    {
        return is_array($this->settings['payment'] ?? null);
    }

    /**
     * The day of the event, when the admin has named it (settings.payment.eventDate,
     * "2026-10-17"), else null. A calendar date, not an instant: staff codes default to
     * midnight at the end of it on the masjid's own clock
     * (FormStaffCodesController::defaultExpiry()). Re-checked on the way out, because
     * settings have more than one door.
     */
    public function eventDate(): ?string
    {
        $payment = $this->settings['payment'] ?? null;
        $date = is_array($payment) ? ($payment['eventDate'] ?? null) : null;

        if (! is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $date : null;
    }

    /**
     * A settings.payment switch, read the way the form door coerces booleans
     * (true, 1, "1", "true", "on"). Anything else — "false", "0", absent, junk —
     * is off.
     */
    private function paymentFlag(string $key): bool
    {
        $payment = $this->settings['payment'] ?? null;

        if (! is_array($payment)) {
            return false;
        }

        return filter_var($payment[$key] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
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
     * ## Priced by the number of entries (`countTiers`; BISS, 2026-09-13)
     *
     * `countTiers` prices the WHOLE registration by how many rows the
     * `perEntryOfSection` section has: [{min:1, amount:100, label:'1 child'},
     * {min:2, amount:170}, …]. Such a form carries no `amount` and no date `tiers`
     * (the save refuses the mix). Its rule adds `pricing: 'count'` and the readable
     * `countTiers`, sorted by `min`; `amount` is the lowest tier's price, for
     * display only, since what is owed depends on the submission —
     * priceFor() resolves it. The keys a unit-priced rule has always had are
     * unchanged, and a unit-priced rule gains none.
     *
     * A count schedule nothing can read makes the WHOLE rule null, so the form
     * refuses entries, rather than skipping the bad tier: skipping one falls back
     * to a cheaper tier, and a silent under-charge is what the date tiers' "an
     * unreadable cut-off steps UP" rule exists to prevent.
     *
     * @return array{amount: float, currency: string, perEntryOfSection: ?string, tiers: array, currentTier: ?array, pricing?: string, countTiers?: array<int,array{min:int,amount:float,label:?string}>}|null
     */
    public function feeRule(?CarbonInterface $at = null): ?array
    {
        $fee = $this->settings['fee'] ?? null;

        if (! is_array($fee)) {
            return null;
        }

        if (self::hasCountTiers($fee)) {
            return self::countFeeRule($fee);
        }

        if (self::hasChoicePrices($fee)) {
            return self::choiceFeeRule($fee);
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

        $rule = [
            'amount' => (float) $amount,
            'currency' => $fee['currency'] ?? 'USD',
            'perEntryOfSection' => $fee['perEntryOfSection'] ?? null,
            'tiers' => $tiers,
            'currentTier' => $currentTier,
        ];

        // Unit price x a quantity question (Zakat-ul-Fitr per person; 2026-09-25). The key
        // is added only when the form has one, so every other unit-priced rule keeps
        // exactly the keys it always had. Beside perEntryOfSection it is unreadable (one
        // of the two counts would be ignored), and the whole rule is null: refused,
        // never under-charged.
        $perQuantity = self::quantityFieldOf($fee);

        if ($perQuantity !== null) {
            if (is_string($rule['perEntryOfSection']) && trim($rule['perEntryOfSection']) !== '') {
                return null;
            }

            $rule['perQuantityOf'] = $perQuantity;
        }

        return $rule;
    }

    /**
     * The quantity question (settings.fee.perQuantityOf): the name of a number question
     * whose whole-number answer is how many units a submission is charged for, or null.
     */
    public function quantityField(): ?string
    {
        $fee = $this->settings['fee'] ?? null;

        return is_array($fee) ? self::quantityFieldOf($fee) : null;
    }

    /**
     * The date list reservations are made from (settings.reservation; Ramadan giving,
     * 2026-09-25): the question that asks for the date and the dates it may name, as
     * valid ISO dates, unique and in order. Null when the form reserves nothing, or when
     * the block cannot be read, so the question offers no date at all.
     *
     * @return array{field: string, dates: array<int,string>}|null
     */
    public function reservation(): ?array
    {
        $block = $this->settings['reservation'] ?? null;

        if (! is_array($block) || ! is_string($block['field'] ?? null) || trim($block['field']) === '') {
            return null;
        }

        $dates = [];

        foreach (is_array($block['dates'] ?? null) ? $block['dates'] : [] as $date) {
            if (is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts) === 1
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $dates[$date] = true;
            }
        }

        $dates = array_keys($dates);
        sort($dates, SORT_STRING);

        return ['field' => trim($block['field']), 'dates' => $dates];
    }

    /**
     * The date this submission reserves, or null when it reserves none: the form has no
     * date list, the price chosen does not reserve a date (Individual Iftar), or the
     * question was left blank. On a form not priced by choice, every submission that
     * names a date reserves it.
     *
     * @param  array<string,mixed>  $data  the cleaned submission
     */
    public function reservedDateIn(array $data): ?string
    {
        $reservation = $this->reservation();

        if ($reservation === null) {
            return null;
        }

        $fee = $this->feeRule();

        if (($fee['pricing'] ?? null) === self::PRICING_CHOICE) {
            $chosen = self::chosenPrice($fee, $data);

            if ($chosen === null || ! $chosen['reservesDate']) {
                return null;
            }
        }

        $date = $data[$reservation['field']] ?? null;

        return is_string($date) && $date !== '' ? $date : null;
    }

    /**
     * The submission without the answers its price does not use, so what is stored and
     * emailed is what was charged and reserved: on a form priced by choice, the quantity
     * answer of a level not charged per unit (a Quarter Iftar with "3" typed in the
     * people box), and a date named beside a level that reserves none.
     *
     * Every other form comes back exactly as it went in.
     *
     * @param  array<string,mixed>  $data  the cleaned submission (FormSchema::only())
     * @return array<string,mixed>
     */
    public function withoutUnusedPriceAnswers(array $data): array
    {
        $fee = $this->feeRule();

        if (($fee['pricing'] ?? null) !== self::PRICING_CHOICE) {
            return $data;
        }

        $chosen = self::chosenPrice($fee, $data);

        if ($chosen === null) {
            return $data;
        }

        $quantity = $fee['perQuantityOf'] ?? null;

        if (! $chosen['perQuantity'] && is_string($quantity)) {
            unset($data[$quantity]);
        }

        $reservation = $this->reservation();

        if (! $chosen['reservesDate'] && $reservation !== null) {
            unset($data[$reservation['field']]);
        }

        return $data;
    }

    /**
     * The label a choice question shows for one of its values, or null. Read from the
     * schema's typed options, so the receipt names the level as the page did.
     */
    public function optionLabel(string $field, string $value): ?string
    {
        foreach ($this->sections() as $section) {
            if (! empty($section['repeatable'])) {
                continue;
            }

            foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $candidate) {
                if (! is_array($candidate) || ($candidate['name'] ?? null) !== $field) {
                    continue;
                }

                foreach (is_array($candidate['options'] ?? null) ? $candidate['options'] : [] as $option) {
                    if (is_array($option) && ($option['value'] ?? null) === $value) {
                        $label = $option['label'] ?? null;

                        return is_string($label) && trim($label) !== '' ? trim($label) : null;
                    }
                }
            }
        }

        return null;
    }

    /** Whether settings.fee prices by the number of entries (any non-empty `countTiers`). */
    public function pricesByCount(): bool
    {
        $fee = $this->settings['fee'] ?? null;

        return is_array($fee) && self::hasCountTiers($fee);
    }

    /**
     * THE price of one submission, in force at $at: every reader of "what does this
     * owe" — FormSchema::amountDue() (the stored decimal), FormPayment::quote() (the
     * cents snapshot and the Stripe line) and the emails' tier label — goes through
     * here, so the three cannot disagree.
     *
     *   flat fee          unit = amount, quantity 1
     *   per entry         unit = amount (the date tier in force), quantity = rows
     *   count tiers       unit = the tier with the GREATEST min <= rows, whatever
     *                     order the tiers were stored in; quantity 1 — or 0 with
     *                     no rows, so an empty list owes nothing and the paying
     *                     form's "Add at least one entry." refusal fires
     *   per quantity      unit = amount (the date tier in force), quantity = the whole
     *                     number answered to the settings.fee.perQuantityOf question
     *                     (Zakat-ul-Fitr: $17 x 4 people). A blank answer is 0, so the
     *                     never-free refusal fires; FormSchema requires it first
     *   by choice         unit = the price of the level the settings.fee.byChoice
     *                     question was answered with (Quarter Iftar $450); quantity =
     *                     the quantity answer for a level charged per unit
     *                     (Individual Iftar $18 x 3), else 1
     *
     * `entries` is the counted section's row count (1 on a flat fee; the quantity on
     * a quantity or choice price); `label` names the tier ("Early bird", "3
     * children") or the level chosen ("Quarter Iftar"), or null.
     *
     * Null when the form charges nothing, or when a count schedule cannot price
     * this many rows (unreadable, or no tier starts low enough), when a choice
     * names no level, or when a quantity is above Form::MAX_QUANTITY. A caller that
     * takes payment refuses the submission on a null; it never charges less.
     *
     * @param  array<string,mixed>  $data  the cleaned submission (FormSchema::only()), or a stored row's data
     * @return array{fee: array<string,mixed>, unit: float, quantity: int, entries: int, label: ?string}|null
     */
    public function priceFor(array $data, ?CarbonInterface $at = null): ?array
    {
        $fee = $this->feeRule($at);

        if ($fee === null) {
            return null;
        }

        $perEntry = $fee['perEntryOfSection'] ?? null;
        $entries = null;

        if ($perEntry !== null) {
            $rows = Arr::get($data, $perEntry, []);
            $entries = is_array($rows) ? count($rows) : 0;
        }

        if (($fee['pricing'] ?? null) === self::PRICING_COUNT) {
            if ($entries === 0) {
                return ['fee' => $fee, 'unit' => (float) $fee['amount'], 'quantity' => 0, 'entries' => 0, 'label' => null];
            }

            $tier = self::resolveCountTier($fee['countTiers'], (int) $entries);

            if ($tier === null) {
                return null;
            }

            return ['fee' => $fee, 'unit' => $tier['amount'], 'quantity' => 1, 'entries' => (int) $entries, 'label' => $tier['label']];
        }

        if (($fee['pricing'] ?? null) === self::PRICING_CHOICE) {
            $chosen = self::chosenPrice($fee, $data);

            if ($chosen === null) {
                return null;
            }

            $quantity = $chosen['perQuantity'] ? self::quantityIn($data, $fee['perQuantityOf']) : 1;

            if ($quantity === null) {
                return null;
            }

            return [
                'fee' => $fee,
                'unit' => $chosen['amount'],
                'quantity' => $quantity,
                'entries' => $quantity,
                'label' => $this->optionLabel($fee['choiceField'], $chosen['value']) ?? $chosen['value'],
            ];
        }

        $label = $fee['currentTier']['label'] ?? null;
        $label = is_string($label) && trim($label) !== '' ? trim($label) : null;

        if (isset($fee['perQuantityOf'])) {
            $quantity = self::quantityIn($data, $fee['perQuantityOf']);

            if ($quantity === null) {
                return null;
            }

            return ['fee' => $fee, 'unit' => (float) $fee['amount'], 'quantity' => $quantity, 'entries' => $quantity, 'label' => $label];
        }

        return [
            'fee' => $fee,
            'unit' => (float) $fee['amount'],
            'quantity' => $entries ?? 1,
            'entries' => $entries ?? 1,
            'label' => $label,
        ];
    }

    /** @param  array<string,mixed>  $fee */
    private static function quantityFieldOf(array $fee): ?string
    {
        $field = $fee['perQuantityOf'] ?? null;

        return is_string($field) && trim($field) !== '' ? trim($field) : null;
    }

    /**
     * The units a quantity question's answer charges for: a whole number, 0 when the
     * answer is blank or not a whole number (the caller refuses a total of 0, and
     * FormSchema has already refused both), or null above MAX_QUANTITY, which is never
     * priced whatever the question's own maximum says.
     *
     * @param  array<string,mixed>  $data
     */
    private static function quantityIn(array $data, mixed $field): ?int
    {
        if (! is_string($field)) {
            return 0;
        }

        $raw = $data[$field] ?? null;
        $quantity = is_bool($raw) ? false : filter_var(is_string($raw) ? trim($raw) : $raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($quantity === false) {
            return 0;
        }

        return $quantity > self::MAX_QUANTITY ? null : $quantity;
    }

    /** @param  array<string,mixed>  $fee */
    private static function hasChoicePrices(array $fee): bool
    {
        // As for count tiers: anything but absent, null or empty is a price list, readable
        // or not, so a junk value makes the price unreadable and never unit-priced.
        return array_key_exists('byChoice', $fee) && $fee['byChoice'] !== null && $fee['byChoice'] !== [];
    }

    /**
     * The fee rule of a form priced by the answer to one choice question, or null when
     * its list cannot be read. All or nothing, as for count tiers: a level that cannot
     * be read must not quietly drop out and leave its payers unpriced.
     *
     * `amount` is the lowest level's price, for display only (the public payload does
     * not publish it: SectionContentBinder::publicFee()); what is owed is the chosen
     * level's, resolved by priceFor().
     *
     * @param  array<string,mixed>  $fee
     * @return array<string,mixed>|null
     */
    private static function choiceFeeRule(array $fee): ?array
    {
        $block = $fee['byChoice'];
        $field = is_array($block) && is_string($block['field'] ?? null) ? trim($block['field']) : '';
        $raw = is_array($block) ? ($block['prices'] ?? null) : null;

        if ($field === '' || ! is_array($raw) || $raw === []) {
            return null;
        }

        $prices = [];

        foreach ($raw as $price) {
            $value = is_array($price) ? ($price['value'] ?? null) : null;
            $amount = is_array($price) ? ($price['amount'] ?? null) : null;

            if (! is_string($value) || $value === '' || isset($prices[$value])
                || is_bool($amount) || ! is_numeric($amount) || (float) $amount < 0 || ! is_finite((float) $amount)) {
                return null;
            }

            $prices[$value] = [
                'value' => $value,
                'amount' => (float) $amount,
                'perQuantity' => filter_var($price['perQuantity'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,
                'reservesDate' => filter_var($price['reservesDate'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,
            ];
        }

        $perQuantity = self::quantityFieldOf($fee);

        // A level charged per unit with no quantity question to count by would be charged
        // for nothing, or for 1 without saying so.
        foreach ($prices as $price) {
            if ($price['perQuantity'] && $perQuantity === null) {
                return null;
            }
        }

        return [
            'amount' => min(array_column($prices, 'amount')),
            'currency' => $fee['currency'] ?? 'USD',
            'perEntryOfSection' => null,
            'tiers' => [],
            'currentTier' => null,
            'pricing' => self::PRICING_CHOICE,
            'choiceField' => $field,
            'choicePrices' => array_values($prices),
            'perQuantityOf' => $perQuantity,
        ];
    }

    /**
     * The price level the submission chose on a form priced by choice, or null when its
     * answer names none.
     *
     * @param  array<string,mixed>|null  $fee  feeRule()
     * @param  array<string,mixed>  $data
     * @return array{value:string,amount:float,perQuantity:bool,reservesDate:bool}|null
     */
    private static function chosenPrice(?array $fee, array $data): ?array
    {
        if (($fee['pricing'] ?? null) !== self::PRICING_CHOICE) {
            return null;
        }

        $answer = $data[$fee['choiceField']] ?? null;

        if (! is_string($answer)) {
            return null;
        }

        foreach ($fee['choicePrices'] as $price) {
            if ($price['value'] === $answer) {
                return $price;
            }
        }

        return null;
    }

    /**
     * The level a submission chose, for the validator and the receipt, or null on a form
     * not priced by choice or when the answer names no level.
     *
     * @param  array<string,mixed>  $data
     * @return array{value:string,amount:float,perQuantity:bool,reservesDate:bool}|null
     */
    public function chosenPriceIn(array $data): ?array
    {
        return self::chosenPrice($this->feeRule(), $data);
    }

    /** @param  array<string,mixed>  $fee */
    private static function hasCountTiers(array $fee): bool
    {
        // Anything but absent, null or an empty list is a count schedule, readable or
        // not: a junk value must make the price unreadable, never quietly unit-priced.
        return array_key_exists('countTiers', $fee) && $fee['countTiers'] !== null && $fee['countTiers'] !== [];
    }

    /**
     * The fee rule of a form priced by count, or null when its schedule cannot be read
     * or it counts no section.
     *
     * @param  array<string,mixed>  $fee
     * @return array<string,mixed>|null
     */
    private static function countFeeRule(array $fee): ?array
    {
        $tiers = self::readableCountTiers($fee['countTiers']);
        $perEntry = $fee['perEntryOfSection'] ?? null;

        if ($tiers === null || ! is_string($perEntry) || trim($perEntry) === '') {
            return null;
        }

        return [
            'amount' => $tiers[0]['amount'],
            'currency' => $fee['currency'] ?? 'USD',
            'perEntryOfSection' => $perEntry,
            'tiers' => [],
            'currentTier' => null,
            'pricing' => self::PRICING_COUNT,
            'countTiers' => $tiers,
        ];
    }

    /**
     * A stored count schedule as whole-number mins and float amounts, sorted by min —
     * or null when ANY tier cannot be read (a min that is not a positive whole
     * number, a missing or negative amount, two tiers with one min). All or nothing:
     * dropping the unreadable tier would price those families at the tier below.
     *
     * @return array<int,array{min:int,amount:float,label:?string}>|null
     */
    private static function readableCountTiers(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $tiers = [];

        foreach ($raw as $tier) {
            if (! is_array($tier)) {
                return null;
            }

            $min = $tier['min'] ?? null;
            $amount = $tier['amount'] ?? null;

            $min = is_bool($min) ? false : filter_var($min, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($min === false || is_bool($amount) || ! is_numeric($amount) || (float) $amount < 0 || ! is_finite((float) $amount)) {
                return null;
            }

            if (isset($tiers[$min])) {
                return null;
            }

            $label = $tier['label'] ?? null;

            $tiers[$min] = [
                'min' => $min,
                'amount' => (float) $amount,
                'label' => is_string($label) && trim($label) !== '' ? trim($label) : null,
            ];
        }

        ksort($tiers);

        return array_values($tiers);
    }

    /**
     * The count tier for $count rows: the one with the greatest `min` that is at most
     * $count. Chosen by comparison, not by position, so a schedule stored as
     * [1, 5, 2] still charges a family of six the "5 or more" price and never the
     * "2 children" one. Null when no tier starts low enough.
     *
     * @param  array<int,array{min:int,amount:float,label:?string}>  $tiers
     * @return array{min:int,amount:float,label:?string}|null
     */
    private static function resolveCountTier(array $tiers, int $count): ?array
    {
        $chosen = null;

        foreach ($tiers as $tier) {
            if ($tier['min'] <= $count && ($chosen === null || $tier['min'] > $chosen['min'])) {
                $chosen = $tier;
            }
        }

        return $chosen;
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
