<?php

namespace App\Http\Requests\Admin\Forms;

use App\Http\Requests\BaseFormRequest;
use App\Models\Form;
use App\Rules\TierCutoff;
use App\Rules\ValidFormSchema;
use App\Support\FormOptionSources;
use App\Support\FormPayment;
use App\Support\FormSchema;
use Illuminate\Validation\Rule;

class StoreFormRequest extends BaseFormRequest
{
    /**
     * The `settings.payment` switches (DECISIONS.md 2026-09-11; the required card fee and
     * paying the office, 2026-09-13), coerced alike on every door.
     */
    public const PAYMENT_FLAGS = ['online', 'staffCodes', 'allowFeeCoverage', 'requireFeeCoverage', 'officePayment'];

    /**
     * The builder may post either JSON or FormData depending on the SPA screen, so
     * `schema` and `settings` can arrive as JSON-encoded strings — decode them before
     * rules() sees them, matching StoreSectionRequest's handling of `content`.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['schema', 'settings'] as $key) {
            if (is_string($this->input($key))) {
                $merge[$key] = json_decode($this->input($key), true);
            }
        }

        // After the decode above, so a JSON-string body and a nested form body are
        // read alike. UpdateFormRequest inherits this, so the PUT coerces too.
        $settings = $merge['settings'] ?? $this->input('settings');

        if (is_array($settings) && is_array($settings['payment'] ?? null)) {
            $merge['settings'] = self::coercePaymentFlags($settings);
        }

        if ($this->has('is_active')) {
            $merge['is_active'] = filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * `settings.payment`'s switches as real booleans, read the way every door must
     * read them before the `boolean` rule does.
     *
     * Laravel's `boolean` accepts true/false/1/0/"1"/"0" and REFUSES the strings
     * "true" and "false" — exactly what a form-encoded body carries
     * (.claude/rules/shipping.md: the cover_fees outage of 2026-09-09). So each
     * switch goes through FILTER_VALIDATE_BOOLEAN ("on"/"off"/"yes"/"no" too),
     * which is also how Form::paymentFlag() reads the stored value. Only a
     * readable answer replaces the value: genuine nonsense is left as it came, so
     * the rule refuses it rather than it quietly meaning "off". A blank becomes
     * null, as ConvertEmptyStringsToNull already makes it on the HTTP doors, so
     * `form:import` — which sits behind no middleware and calls this too — stores
     * exactly what POST stores.
     */
    public static function coercePaymentFlags(mixed $settings): mixed
    {
        if (! is_array($settings) || ! is_array($settings['payment'] ?? null)) {
            return $settings;
        }

        foreach (self::PAYMENT_FLAGS as $flag) {
            if (! array_key_exists($flag, $settings['payment'])) {
                continue;
            }

            $value = $settings['payment'][$flag];

            if (is_string($value) && trim($value) === '') {
                $settings['payment'][$flag] = null;

                continue;
            }

            if ($value === null || is_bool($value) || is_array($value)) {
                continue;
            }

            $coerced = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($coerced !== null) {
                $settings['payment'][$flag] = $coerced;
            }
        }

        return $settings;
    }

    public function rules(): array
    {
        return [
            // Slug is unique per masjid, mirroring the pages table. The masjid comes
            // from the route, not the payload, so tenancy cannot be spoofed here.
            //
            // THE ONE RULE THE IMPORTER DELIBERATELY DOES NOT SHARE: `form:import`
            // is idempotent by (masjid_id, slug) — re-running it UPDATES the row
            // and restores a soft-deleted one — so a "duplicate" slug is its
            // success case, not its refusal. Everything else the two doors accept
            // is in documentRules() + settingsRules(), and FormDoorEquivalenceTest
            // proves the two doors agree document for document.
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('forms', 'slug')
                    ->where('masjid_id', (int) $this->route('masjid_id'))
                    ->whereNull('deleted_at'),
            ],
        ] + self::documentRules() + self::settingsRules();
    }

    /**
     * Everything about a form definition EXCEPT its slug and its `settings` —
     * exposed so the IMPORTER applies the same rules rather than a paraphrase.
     *
     * The two that were measurably a paraphrase before this round:
     *
     *   capacity 2000000    form:import ACCEPT      POST 422      PUT 422
     *   is_active null      form:import ACCEPT      POST 201      (both accepted,
     *                       and stored `true`      and stored     and stored
     *                                              `false`)       OPPOSITE forms
     *
     * A capacity nobody can fulfil and a form that is live through one door and
     * switched off through the other are both the same defect as the fee one:
     * a sentence written about the builder and believed about the importer.
     *
     * @return array<string,mixed>
     */
    public static function documentRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'schema' => ['required', 'array', new ValidFormSchema()],
            'is_active' => 'boolean',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date|after_or_equal:opens_at',
            'capacity' => 'nullable|integer|min:1|max:1000000',
        ];
    }

    /**
     * The `settings` rules, exposed so the IMPORTER applies the same ones.
     *
     * `ImportFormCommand`'s docblock has always promised that "the schema is
     * validated through the SAME rule the admin API uses, so a file cannot
     * introduce a form the builder would have rejected". That was true of
     * `schema` (both go through `ValidFormSchema`) and measurably false of
     * `settings`, which is where THE FEE LIVES: the command validated it as
     * `nullable|array` and nothing more. Measured on the same payload —
     *
     *     form:import with tiers[0].until = '2026-8-14'  -> exit 0, ACCEPTED
     *     POST /api/admin/masjids/{id}/forms, unpadded   -> 422 "…format Y-m-d"
     *     POST /api/admin/masjids/{id}/forms, padded     -> 201
     *
     * — and the accepted file then priced four months of registrations at the
     * expired early-bird rate, because `Form::resolveTier()` compared the
     * cut-off as a string (see `Form::normaliseCutoff()`). Two independent
     * defects, one of which alone would have been harmless.
     *
     * Kept as a static array rather than duplicated prose so the two doors
     * cannot drift: a rule added here reaches the builder, the PATCH and the
     * importer in the same edit.
     *
     * @return array<string,mixed>
     */
    public static function settingsRules(): array
    {
        return [
            'settings' => 'nullable|array',
            'settings.submitButtonLabel' => 'nullable|string|max:80',
            'settings.successTitle' => 'nullable|string|max:255',
            'settings.successBody' => 'nullable|string|max:5000',
            'settings.successNextSteps' => 'nullable|array|max:10',
            'settings.successNextSteps.*' => 'string|max:255',
            'settings.notifyEmails' => 'nullable|array|max:10',
            // `,filter` — the same FILTER_VALIDATE_EMAIL the SEND path uses. `email:rfc`
            // alone accepts addresses FormNotifier::coordinatorRecipients() then drops,
            // and dropping every recipient sends the form back to the masjid's own
            // address: the office is stored, shown in the builder, and never mailed, with
            // nothing on screen to say so. Measured as accepted by rfc and refused by the
            // notifier: office@intranet, office@localhost, "john doe"@example.com, a@b.
            // It is also the only length bound `email:` has — filter caps the local part
            // at 64 characters and each domain label at 63, which is what refuses the
            // 100,012-character address that `email:rfc` stored whole in this json column.
            'settings.notifyEmails.*' => 'email:rfc,filter',
            'settings.intro' => 'nullable|string|max:20000',

            // Whether the submitter gets a copy. Absent means yes — a form that collects an
            // email address should acknowledge it by default.
            'settings.confirmationEmail' => 'nullable|boolean',
            // Shown on the receipt where money is involved: card surcharges, where to pay,
            // when payment is due. Kept as form data so nothing masjid-specific is written
            // into the mail template.
            'settings.paymentNote' => 'nullable|string|max:1000',

            // Which schema field feeds each searchable column on form_responses.
            'settings.identity' => 'nullable|array',
            // Each slot is one question name, or a list of them joined with a space
            // (first + last name).
            'settings.identity.name' => 'nullable',
            'settings.identity.name.*' => 'string|max:255',
            'settings.identity.email' => 'nullable',
            'settings.identity.email.*' => 'string|max:255',
            'settings.identity.phone' => 'nullable',
            'settings.identity.phone.*' => 'string|max:255',

            'settings.fee' => 'nullable|array',
            // amount is optional when tiers carry the pricing instead.
            'settings.fee.amount' => 'nullable|numeric|min:0|max:1000000',
            // Date-stepped pricing: early bird -> standard -> day-of. `until` is
            // INCLUSIVE; the last tier normally omits it as the open-ended final price.
            //
            // The whole cut-off contract — including the BLANK spellings that
            // `nullable|date_format:Y-m-d` silently let through, and what each of
            // them cost — is stated once in App\Rules\TierCutoff. Loosening it
            // re-opens a $40-per-attendee under-charge; TieredFeeTest pins the
            // read half and FormDoorEquivalenceTest pins that every door applies it.
            'settings.fee.tiers' => 'nullable|array|max:10',
            'settings.fee.tiers.*.amount' => 'required|numeric|min:0|max:1000000',
            'settings.fee.tiers.*.until' => [new TierCutoff()],
            'settings.fee.tiers.*.label' => 'nullable|string|max:60',
            'settings.fee.currency' => 'nullable|string|size:3',
            'settings.fee.perEntryOfSection' => 'nullable|string|max:255',
            // Priced by the number of entries (BISS, 2026-09-13): the WHOLE registration
            // costs the tier with the greatest `min` at or below the counted section's
            // row count (Form::priceFor()). Every key has a rule, because a key without
            // one is dropped by validated() while the save still succeeds — and a form
            // whose count prices vanished would be free. The shape across tiers (first
            // min 1, ascending, never cheaper, not beside `amount`/`tiers`) is
            // countTierProblems() in crossCheck().
            'settings.fee.countTiers' => 'nullable|array|max:10',
            'settings.fee.countTiers.*.min' => 'required|integer|min:1|max:1000',
            'settings.fee.countTiers.*.amount' => 'required|numeric|min:0|max:1000000',
            'settings.fee.countTiers.*.label' => 'nullable|string|max:60',
            // Unit price x the whole-number answer to a number question (Zakat-ul-Fitr per
            // person; Ramadan giving, 2026-09-25). The question's shape (flat, a number,
            // required, bounded) is quantityProblems() in crossCheck().
            'settings.fee.perQuantityOf' => 'nullable|string|max:255',
            // Priced by the answer to one choice question (iftar sponsorship levels): each
            // option's price, whether it is charged per unit of the quantity question, and
            // whether it reserves a date from settings.reservation. Every key has a rule, or
            // validated() would drop it and the level would lose its price or its date.
            'settings.fee.byChoice' => 'nullable|array',
            'settings.fee.byChoice.field' => 'required_with:settings.fee.byChoice|string|max:255',
            'settings.fee.byChoice.prices' => 'required_with:settings.fee.byChoice|array|min:1|max:20',
            'settings.fee.byChoice.prices.*.value' => 'required|string|max:255',
            'settings.fee.byChoice.prices.*.amount' => 'required|numeric|min:0|max:1000000',
            'settings.fee.byChoice.prices.*.perQuantity' => 'nullable|boolean',
            'settings.fee.byChoice.prices.*.reservesDate' => 'nullable|boolean',

            // The dates a registration may reserve, one per registration, never twice
            // (App\Support\FormReservations). An empty list is allowed: the question then
            // offers nothing and refuses every date, which is how a form is imported before
            // its organisation has named the days.
            'settings.reservation' => 'nullable|array',
            'settings.reservation.field' => 'required_with:settings.reservation|string|max:255',
            'settings.reservation.dates' => 'nullable|array|max:100',
            'settings.reservation.dates.*' => 'date_format:Y-m-d|distinct',

            // Payment (DECISIONS.md 2026-09-11). Absent means off, which is every
            // form written before the festival. Each switch is a real boolean by
            // the time these run — coercePaymentFlags() reads a form-encoded
            // "true" first, on POST, PUT and form:import alike — and turning one
            // on puts the fee under the stricter rules in crossCheck().
            'settings.payment' => 'nullable|array',
            'settings.payment.online' => 'nullable|boolean',
            'settings.payment.staffCodes' => 'nullable|boolean',
            'settings.payment.allowFeeCoverage' => 'nullable|boolean',
            // Every card payer covers the card fee; the browser cannot turn it off
            // (FormPayment::feeCoveredMinor()). A separate key, so allowFeeCoverage
            // keeps meaning "an optional checkbox" to every renderer already deployed.
            'settings.payment.requireFeeCoverage' => 'nullable|boolean',
            // The family may choose to pay the office; staff record how the money came.
            // Turning it on puts the fee under the paying-form rules like the other two.
            'settings.payment.officePayment' => 'nullable|boolean',
            // How to pay the office, in its own words (handles, hours). Published on the
            // page and in the "amount owed" email, so nothing private belongs here.
            'settings.payment.officeInstructions' => 'nullable|string|max:1000',
            // The day of the event: staff codes default to expiring at the end of it, on
            // the masjid's clock (Form::eventDate()). A calendar date and nothing else.
            // A form carries no other event day, and guessing one from closes_at put
            // every code's end at midnight on the festival's own morning.
            'settings.payment.eventDate' => 'nullable|date_format:Y-m-d',

            // The group link a registrant gets once they are settled. It is never
            // published on the page (SectionContentBinder::bindForm()), so only a
            // chat.whatsapp.com invite gets in: the pattern is Form's own, the one
            // Form::whatsappUrl() re-checks on the way out, so the door and the
            // reader cannot disagree about what a WhatsApp link is.
            'settings.whatsappUrl' => ['nullable', 'string', 'max:120', 'regex:' . Form::WHATSAPP_URL_PATTERN],
            'settings.whatsappLabel' => 'nullable|string|max:80',
        ];
    }

    /**
     * Cross-field checks that need the whole schema in hand: the identity map and the
     * fee rule both reference field/section names, and a typo in either fails silently
     * at runtime (an unsearchable response list, or a total of zero) rather than loudly.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $schema = $this->input('schema');

            if (! is_array($schema) || $validator->errors()->has('schema')) {
                return;
            }

            foreach (self::crossCheck($schema, (array) $this->input('settings', [])) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    /**
     * THE cross-check, as data rather than as three implementations of the same
     * paragraph: does every question and section `settings` names actually exist
     * in `$schema`?
     *
     * ## Why it is static, and why the importer calls it
     *
     * `ImportFormCommand` carried its own copy (`crossCheck()`, near-identical,
     * separately maintained) and `UpdateFormRequest` carried none at all on a
     * partial write. Measured on this branch, one document, three doors:
     *
     *     perEntryOfSection 'nope'   form:import REJECT   POST 422
     *                                PUT {settings} only  200  <- accepted
     *     identity.name     'nope'   form:import REJECT   POST 422
     *                                PUT {settings} only  200  <- accepted
     *
     * and what the accepted one costs, measured end to end on a camp form with a
     * $100-per-attendee fee and two attendees:
     *
     *     before  FormSchema::amountDue() = 200.0
     *     PUT {"settings":{"fee":{…,"perEntryOfSection":"attendee"}}}  ->  200 OK
     *     after   FormSchema::amountDue() = 0.0
     *
     * One singular typo, a 200, and the camp is free. `amountDue()` reads the
     * submitted rows at `$data[$perEntryOfSection]`, finds nothing, multiplies by
     * zero and stores that on the response — so it is not a display bug that the
     * next save corrects, it is the number the family agreed to pay.
     *
     * The same hole ran the other way and is closed with it: a PUT carrying only
     * a NEW `schema` was accepted even when it deleted the very section the
     * STORED fee is charged per entry of. UpdateFormRequest resolves both halves
     * (payload where present, stored row otherwise) and hands the pair here, so
     * "a form that is valid to create must remain valid to edit" — that class's
     * own claim — is enforced rather than asserted.
     *
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $settings
     * @return array<string,string> field => message; empty when the pair is coherent
     */
    public static function crossCheck(array $schema, array $settings): array
    {
        [$flatNames, $repeatableIds] = self::schemaNames($schema);

        $problems = [];

        foreach (['name', 'email', 'phone'] as $slot) {
            $declared = $settings['identity'][$slot] ?? null;

            if ($declared === null || $declared === '') {
                continue;
            }

            foreach ((array) $declared as $field) {
                if (! in_array($field, $flatNames, true)) {
                    $label = is_string($field) ? $field : json_encode($field);
                    $problems["settings.identity.{$slot}"] =
                        "\"{$label}\" is not a question in this form, so it cannot be used for the {$slot}.";
                }
            }
        }

        $perEntry = $settings['fee']['perEntryOfSection'] ?? null;

        if ($perEntry !== null && $perEntry !== '' && ! in_array($perEntry, $repeatableIds, true)) {
            $label = is_string($perEntry) ? $perEntry : json_encode($perEntry);
            $problems['settings.fee.perEntryOfSection'] =
                "\"{$label}\" is not a repeatable section, so the fee cannot be charged per entry of it.";
        }

        // A key already refused above keeps its first, more specific message. The count
        // schedule is checked on EVERY form, paying or not, because amount_due is
        // stored from it either way.
        foreach ([self::countTierProblems($schema, $settings), self::quantityProblems($schema, $settings), self::choiceProblems($schema, $settings), self::reservationProblems($schema, $settings), self::staffCodeProblems($settings), self::paymentProblems($schema, $settings)] as $found) {
            foreach ($found as $field => $message) {
                $problems[$field] ??= $message;
            }
        }

        return $problems;
    }

    /**
     * A price by number of entries that could silently charge the wrong amount
     * (BISS, 2026-09-13). Refused when `countTiers` is sent:
     *
     *  - beside `amount` or date `tiers`: one of the two would be ignored without a
     *    word, the defect class every rule in this file exists to stop;
     *  - with no `perEntryOfSection`: nothing would be counted;
     *  - on a counted section with no `maxEntries`: the top tier is open-ended, so one
     *    registration could carry any number of entries at its price;
     *  - with a first `min` other than 1: some family sizes would have no price;
     *  - with mins not strictly ascending (a repeat included): the schedule is read by
     *    comparison, but a list an office cannot read top to bottom is a typo waiting;
     *  - with a tier cheaper than the one before it: $35 typed for $350.
     *
     * Values the field rules already refuse (a min that is not a whole number, an
     * amount that is not a number) are left to those rules. The 50-cent minimum and
     * whole cents reach the tier prices through paymentProblems().
     *
     * @param  array<string,mixed>  $settings
     * @return array<string,string>
     */
    private static function countTierProblems(array $schema, array $settings): array
    {
        $fee = is_array($settings['fee'] ?? null) ? $settings['fee'] : [];
        $tiers = $fee['countTiers'] ?? null;

        if (! is_array($tiers) || $tiers === []) {
            return [];
        }

        $problems = [];

        if (($fee['amount'] ?? null) !== null || (is_array($fee['tiers'] ?? null) && $fee['tiers'] !== [])) {
            $problems['settings.fee.countTiers'] =
                'Prices by number of entries replace the flat price and the date steps. Remove the amount and the date steps, or the prices by number of entries.';
        }

        $perEntry = $fee['perEntryOfSection'] ?? null;

        if ($perEntry === null || (is_string($perEntry) && trim($perEntry) === '')) {
            $problems['settings.fee.perEntryOfSection'] =
                'Prices by number of entries need the repeatable section whose entries are counted (for example, children).';
        } elseif (is_string($perEntry) && ($section = self::repeatableSection($schema, $perEntry)) !== null) {
            // The top tier is open-ended ("5 or more"), so without a cap one registration
            // could carry any number of entries at that price (abuse review, 2026-09-14).
            // A name that is not a repeatable section at all is refused by crossCheck().
            $max = $section['maxEntries'] ?? null;

            if (! is_numeric($max) || (int) $max < 1) {
                $problems['settings.fee.perEntryOfSection'] =
                    "\"{$perEntry}\" needs a maximum number of entries when the form is priced by number of entries, or one registration could add entries without limit at the top price.";
            }
        }

        $previous = null;

        foreach (array_values($tiers) as $i => $tier) {
            $min = is_array($tier) && ! is_bool($tier['min'] ?? null)
                ? filter_var($tier['min'] ?? null, FILTER_VALIDATE_INT)
                : false;
            $amount = is_array($tier) ? ($tier['amount'] ?? null) : null;

            if ($min === false) {
                continue;
            }

            if ($i === 0 && $min !== 1) {
                $problems['settings.fee.countTiers.0.min'] =
                    'The first price by number of entries must start at 1, so every family size has a price.';
            }

            if ($previous !== null && $min <= $previous['min']) {
                $problems["settings.fee.countTiers.{$i}.min"] =
                    'Each price by number of entries must start above the one before it: list them in order, with no number twice.';
            } elseif ($previous !== null && is_numeric($amount) && is_numeric($previous['amount']) && (float) $amount < (float) $previous['amount']) {
                $problems["settings.fee.countTiers.{$i}.amount"] =
                    'A price for more entries cannot be lower than the price for fewer. Check this amount for a typo.';
            }

            $previous = ['min' => $min, 'amount' => $amount];
        }

        return $problems;
    }

    /**
     * NEVER FREE BY ACCIDENT (festival brief, blocker 1): what a form must be
     * before it may take money — card payment (`payment.online`), cash by staff
     * code (`payment.staffCodes`) or paying the office (`payment.officePayment`,
     * BISS 2026-09-13; an office-only form owes money like the other two, and a
     * registration owing $0 at the office is the free path by another name). With
     * any switch on:
     *
     *  1. The fee is charged per entry of a repeatable section that DEMANDS at
     *     least one entry. FormSchema::validator() adds a minimum-rows rule only
     *     for minEntries > 0 and the renderer's minimum is client-side only, so
     *     otherwise an empty attendee list posts straight past both, and
     *     FormSchema::amountDue() multiplies the price by zero. A flat fee is not
     *     allowed on a paying form: the festival charges per attendee (owner,
     *     2026-09-10), and one per-entry rule is what the door counts bracelets by.
     *  2. EVERY price — the flat amount, each date tier (not only the one in force
     *     today) and each price by number of entries — is at least Stripe's 50¢
     *     and a whole number of cents. A $0 early-bird tier would make the form
     *     free until its cut-off; 12.345 has no honest cent value
     *     (FormPayment::wholeMinor()). Paying the office on a form with no price
     *     above zero is refused by name.
     *  3. A card payment is in US dollars, the only currency the Checkout path is
     *     built and tested for. Cash by code carries no such limit.
     *
     * The switches are read as Form::paymentFlag() reads them, so a switch this
     * lets through is exactly a switch the model acts on. Values the field rules
     * already refuse (a non-numeric price) are left to those rules.
     *
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $settings
     * @return array<string,string>
     */
    private static function paymentProblems(array $schema, array $settings): array
    {
        $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];
        $online = self::switchedOn($payment['online'] ?? null);
        $office = self::switchedOn($payment['officePayment'] ?? null);

        // The card fee is optional OR required, never both: a renderer that predates the
        // required fee would draw an unticked box for a fee the server adds anyway. Refused
        // on every form, paying or not, so switching card payment on later cannot inherit it.
        $bothFeeSwitches = [];

        if (self::switchedOn($payment['allowFeeCoverage'] ?? null) && self::switchedOn($payment['requireFeeCoverage'] ?? null)) {
            $bothFeeSwitches['settings.payment.requireFeeCoverage'] =
                'The card fee is either an optional checkbox or always added for card payers, not both. Turn one of them off.';
        }

        if (! $online && ! $office && ! self::switchedOn($payment['staffCodes'] ?? null)) {
            return $bothFeeSwitches;
        }

        $fee = is_array($settings['fee'] ?? null) ? $settings['fee'] : [];
        $problems = $bothFeeSwitches;

        $perEntry = $fee['perEntryOfSection'] ?? null;

        // A quantity question or a price by answer is the other way a paying form says what
        // it charges for (Ramadan giving, 2026-09-25); their own checks make both required,
        // so neither can owe nothing. Only a plain flat fee still needs the per-entry rule.
        $countsOtherwise = self::given($fee['perQuantityOf'] ?? null) || self::given($fee['byChoice'] ?? null);

        if ($countsOtherwise) {
            // Checked by quantityProblems() and choiceProblems().
        } elseif ($perEntry === null || (is_string($perEntry) && trim($perEntry) === '')) {
            $problems['settings.fee.perEntryOfSection'] =
                'A form that takes payment must charge its fee per entry of a repeatable section (for example, per attendee), per a quantity question, or by the answer to a choice question.';
        } elseif (is_string($perEntry) && ($section = self::repeatableSection($schema, $perEntry)) !== null) {
            $min = $section['minEntries'] ?? 0;

            if (! is_numeric($min) || (int) $min < 1) {
                $problems['settings.fee.perEntryOfSection'] =
                    "\"{$perEntry}\" must require at least one entry on a form that takes payment, or a registration with no entries would owe nothing.";
            }
        }
        // A name that is not a repeatable section at all is refused by crossCheck() above.

        $prices = [];

        if (($fee['amount'] ?? null) !== null) {
            $prices['settings.fee.amount'] = $fee['amount'];
        }

        foreach (is_array($fee['tiers'] ?? null) ? $fee['tiers'] : [] as $i => $tier) {
            $prices["settings.fee.tiers.{$i}.amount"] = is_array($tier) ? ($tier['amount'] ?? null) : null;
        }

        // A form priced only by count is a paying form with prices, never "needs a price".
        foreach (is_array($fee['countTiers'] ?? null) ? array_values($fee['countTiers']) : [] as $i => $tier) {
            $prices["settings.fee.countTiers.{$i}.amount"] = is_array($tier) ? ($tier['amount'] ?? null) : null;
        }

        // So is one priced only by answer: every level's price, not only the lowest.
        $byChoice = is_array($fee['byChoice'] ?? null) ? $fee['byChoice'] : [];

        foreach (is_array($byChoice['prices'] ?? null) ? array_values($byChoice['prices']) : [] as $i => $price) {
            $prices["settings.fee.byChoice.prices.{$i}.amount"] = is_array($price) ? ($price['amount'] ?? null) : null;
        }

        if ($prices === []) {
            $problems['settings.fee'] = 'A form that takes payment needs a price.';
        }

        $priced = array_filter($prices, fn ($amount) => is_numeric($amount) && (float) $amount > 0);

        if ($office && $priced === []) {
            $problems['settings.payment.officePayment'] =
                'Paying the office needs a price on the form. This form charges nothing, so there would be nothing to pay.';
        }

        foreach ($prices as $field => $amount) {
            if (! is_numeric($amount)) {
                continue;
            }

            $minor = FormPayment::wholeMinor($amount);

            if ($minor === null) {
                $problems[$field] = 'A price on a form that takes payment must be in whole cents (at most two decimal places).';
            } elseif ($minor < FormPayment::MIN_CHARGE_MINOR) {
                $problems[$field] = 'Every price on a form that takes payment must be at least $0.50, the smallest amount a card can be charged.';
            }
        }

        $currency = $fee['currency'] ?? null;

        if ($online && $currency !== null && strtoupper(trim((string) $currency)) !== 'USD') {
            $problems['settings.fee.currency'] = 'Card payment is available in US dollars (USD) only.';
        }

        return $problems;
    }

    /**
     * A price multiplied by a quantity question (settings.fee.perQuantityOf; Ramadan
     * giving, 2026-09-25) that could charge the wrong amount. Refused when:
     *
     *  - it names no NUMBER question in a section that does not repeat: nothing would be
     *    counted, and the charge would silently be for 0;
     *  - it sits beside perEntryOfSection or count prices: one of the two counts would be
     *    ignored without a word (Form::feeRule() reads the pair as unreadable);
     *  - the question lets the answer go below 1, or above Form::MAX_QUANTITY, or has a
     *    maximum below its minimum. A bound that is not a whole number is refused too:
     *    the answer must be a whole number (FormSchema);
     *  - on a form NOT priced by answer, the question is not required: a blank answer
     *    would owe nothing. Priced by answer, only the levels charged per unit ask it,
     *    and FormSchema requires it for those.
     *
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $settings
     * @return array<string,string>
     */
    private static function quantityProblems(array $schema, array $settings): array
    {
        $fee = is_array($settings['fee'] ?? null) ? $settings['fee'] : [];
        $name = $fee['perQuantityOf'] ?? null;

        if (! self::given($name)) {
            return [];
        }

        $key = 'settings.fee.perQuantityOf';
        $label = is_string($name) ? $name : json_encode($name);
        $field = is_string($name) ? self::flatField($schema, $name) : null;

        if ($field === null || ($field['type'] ?? null) !== 'number') {
            return [$key => "\"{$label}\" is not a number question in a section that does not repeat, so the price cannot be multiplied by it."];
        }

        if (self::given($fee['perEntryOfSection'] ?? null) || self::given($fee['countTiers'] ?? null)) {
            return [$key => 'A price per quantity replaces charging per entry and prices by number of entries. Remove one of them.'];
        }

        $min = $field['min'] ?? null;
        $max = $field['max'] ?? null;
        $whole = fn (mixed $v): bool => is_numeric($v) && (float) $v == floor((float) $v);

        if ($min !== null && (! $whole($min) || (int) $min < 1)) {
            return [$key => "\"{$label}\" must not accept fewer than 1: set its minimum to a whole number of at least 1, or leave it empty."];
        }

        if ($max !== null && (! $whole($max) || (int) $max < max(1, (int) ($min ?? 1)) || (int) $max > Form::MAX_QUANTITY)) {
            return [$key => "\"{$label}\" needs a whole-number maximum between its minimum and " . Form::MAX_QUANTITY . ', or no maximum.'];
        }

        if (! self::given($fee['byChoice'] ?? null) && empty($field['required'])) {
            return [$key => "\"{$label}\" must be required, or a registration that leaves it blank would owe nothing."];
        }

        return [];
    }

    /**
     * A price by answer (settings.fee.byChoice; iftar sponsorship levels, 2026-09-25)
     * that could charge the wrong amount or reserve nothing. Refused when:
     *
     *  - it sits beside the flat amount, date steps, count prices or perEntryOfSection:
     *    two prices for one registration, one silently ignored;
     *  - it names no required dropdown or choose-one question with its own typed options
     *    in a section that does not repeat: nothing, or a blank, would be priced;
     *  - an option has no price, a price names no option, or a value is priced twice;
     *  - a level is charged per unit with no quantity question, or reserves a date on a
     *    form with no date list.
     *
     * The 50-cent minimum and whole cents reach each level's price through
     * paymentProblems().
     *
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $settings
     * @return array<string,string>
     */
    private static function choiceProblems(array $schema, array $settings): array
    {
        $fee = is_array($settings['fee'] ?? null) ? $settings['fee'] : [];
        $block = $fee['byChoice'] ?? null;

        if (! self::given($block)) {
            return [];
        }

        if (! is_array($block)) {
            return ['settings.fee.byChoice' => 'Prices by answer must name a question and list a price for each of its choices.'];
        }

        if (($fee['amount'] ?? null) !== null || self::given($fee['tiers'] ?? null) || self::given($fee['countTiers'] ?? null) || self::given($fee['perEntryOfSection'] ?? null)) {
            return ['settings.fee.byChoice' => 'Prices by answer replace the flat price, the date steps, prices by number of entries and charging per entry. Remove the others.'];
        }

        $name = $block['field'] ?? null;
        $label = is_string($name) ? $name : json_encode($name);
        $field = is_string($name) ? self::flatField($schema, $name) : null;

        if ($field === null || ! in_array($field['type'] ?? null, ['select', 'radio'], true) || isset($field['optionsSource'])) {
            return ['settings.fee.byChoice.field' => "\"{$label}\" is not a dropdown or choose-one question with its own choices in a section that does not repeat, so it cannot set the price."];
        }

        if (empty($field['required'])) {
            return ['settings.fee.byChoice.field' => "\"{$label}\" must be required, or a registration that leaves it blank would have no price."];
        }

        $options = [];

        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null)) {
                $options[$option['value']] = true;
            }
        }

        $problems = [];
        $priced = [];
        $prices = is_array($block['prices'] ?? null) ? array_values($block['prices']) : [];

        foreach ($prices as $i => $price) {
            $value = is_array($price) ? ($price['value'] ?? null) : null;

            if (! is_string($value)) {
                continue; // the field rule reports it
            }

            if (! isset($options[$value])) {
                $problems["settings.fee.byChoice.prices.{$i}.value"] = "\"{$value}\" is not one of the choices of \"{$label}\".";
            } elseif (isset($priced[$value])) {
                $problems["settings.fee.byChoice.prices.{$i}.value"] = "\"{$value}\" has two prices. Give each choice one price.";
            }

            $priced[$value] = true;

            if (self::switchedOn($price['perQuantity'] ?? null) && ! self::given($fee['perQuantityOf'] ?? null)) {
                $problems["settings.fee.byChoice.prices.{$i}.perQuantity"] = 'A price charged per unit needs the quantity question it is multiplied by (the price per quantity).';
            }

            if (self::switchedOn($price['reservesDate'] ?? null) && ! is_array($settings['reservation'] ?? null)) {
                $problems["settings.fee.byChoice.prices.{$i}.reservesDate"] = 'A price that reserves a date needs the form\'s list of dates.';
            }
        }

        $unpriced = array_diff(array_keys($options), array_keys($priced));

        if ($unpriced !== [] && ! isset($problems['settings.fee.byChoice.prices'])) {
            $problems['settings.fee.byChoice.prices'] = 'Every choice of "' . $label . '" needs a price. Missing: ' . implode(', ', $unpriced) . '.';
        }

        return $problems;
    }

    /**
     * A date list (settings.reservation) that would reserve nothing, or reserve without
     * asking. Refused when:
     *
     *  - it names no dropdown or choose-one question taking its choices from this form's
     *    reservable dates, in a section that does not repeat;
     *  - on a form priced by answer, no level reserves a date: the list would be ignored;
     *  - on any other form, the question is not required: every registration reserves
     *    the date it names, so one that names none reserves nothing it paid for.
     *
     * And a question drawing on reservable dates with no list naming it offers nothing
     * and refuses every answer, so that is refused too.
     *
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $settings
     * @return array<string,string>
     */
    private static function reservationProblems(array $schema, array $settings): array
    {
        $block = $settings['reservation'] ?? null;
        $named = is_array($block) && is_string($block['field'] ?? null) ? $block['field'] : null;

        foreach (is_array($schema['sections'] ?? null) ? $schema['sections'] : [] as $section) {
            foreach (is_array($section) && is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
                if (is_array($field) && ($field['optionsSource'] ?? null) === FormOptionSources::RESERVABLE_DATES
                    && ($field['name'] ?? null) !== $named) {
                    $question = is_string($field['name'] ?? null) ? $field['name'] : '?';

                    return ['settings.reservation' => "\"{$question}\" takes its choices from this form's reservable dates, so the form needs a date list naming it."];
                }
            }
        }

        if (! is_array($block)) {
            return [];
        }

        $label = $named ?? json_encode($block['field'] ?? null);
        $field = $named !== null ? self::flatField($schema, $named) : null;

        if ($field === null || ! in_array($field['type'] ?? null, FormOptionSources::RESERVABLE_TYPES, true)
            || ($field['optionsSource'] ?? null) !== FormOptionSources::RESERVABLE_DATES) {
            return ['settings.reservation.field' => "\"{$label}\" is not a dropdown or choose-one question taking its choices from this form's reservable dates, in a section that does not repeat."];
        }

        $fee = is_array($settings['fee'] ?? null) ? $settings['fee'] : [];
        $byChoice = $fee['byChoice'] ?? null;

        if (self::given($byChoice)) {
            $reserving = array_filter(
                is_array($byChoice) && is_array($byChoice['prices'] ?? null) ? $byChoice['prices'] : [],
                fn ($price) => is_array($price) && self::switchedOn($price['reservesDate'] ?? null)
            );

            if ($reserving === []) {
                return ['settings.reservation' => 'No price reserves a date, so the list of dates would never be used. Mark the prices that reserve a date, or remove the list.'];
            }

            return [];
        }

        if (empty($field['required'])) {
            return ['settings.reservation.field' => "\"{$label}\" must be required: every registration on this form reserves the date it names."];
        }

        return [];
    }

    /**
     * Staff codes on a form priced by a quantity question or by answer (Ramadan giving,
     * 2026-09-25). The renderer's staff button states what to collect from the unit x
     * rows price, which such a form does not publish, so it would read "Record $0.00"
     * while the server records the real amount. Refused until the renderer can price
     * them; Form::takesStaffCodes() is the read half for a form stored another way.
     *
     * @param  array<string,mixed>  $settings
     * @return array<string,string>
     */
    private static function staffCodeProblems(array $settings): array
    {
        $fee = is_array($settings['fee'] ?? null) ? $settings['fee'] : [];
        $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];

        if (! self::switchedOn($payment['staffCodes'] ?? null)
            || (! self::given($fee['perQuantityOf'] ?? null) && ! self::given($fee['byChoice'] ?? null))) {
            return [];
        }

        return ['settings.payment.staffCodes' => 'Staff codes cannot be used on a form priced per quantity or by the answer to a question yet: the staff screen cannot show how much to collect. Turn staff codes off.'];
    }

    /** Anything but absent, null, a blank string or an empty list. */
    private static function given(mixed $value): bool
    {
        return $value !== null && $value !== [] && ! (is_string($value) && trim($value) === '');
    }

    /**
     * The field with this name in a section that does not repeat, or null.
     *
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>|null
     */
    private static function flatField(array $schema, string $name): ?array
    {
        foreach (is_array($schema['sections'] ?? null) ? $schema['sections'] : [] as $section) {
            if (! is_array($section) || ! empty($section['repeatable'])) {
                continue;
            }

            foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
                if (is_array($field) && ($field['name'] ?? null) === $name) {
                    return $field;
                }
            }
        }

        return null;
    }

    /** A payment switch, read exactly as Form::paymentFlag() reads the stored value. */
    private static function switchedOn(mixed $value): bool
    {
        return filter_var($value ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * The repeatable section with this id, or null.
     *
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>|null
     */
    private static function repeatableSection(array $schema, string $id): ?array
    {
        foreach (is_array($schema['sections'] ?? null) ? $schema['sections'] : [] as $section) {
            if (is_array($section) && ! empty($section['repeatable']) && ($section['id'] ?? null) === $id) {
                return $section;
            }
        }

        return null;
    }

    /**
     * @return array{0:array<int,string>,1:array<int,string>} [flat field names, repeatable section ids]
     */
    private static function schemaNames(array $schema): array
    {
        $flat = [];
        $repeatable = [];

        foreach ($schema['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }

            if (! empty($section['repeatable'])) {
                if (isset($section['id'])) {
                    $repeatable[] = $section['id'];
                }

                continue;
            }

            foreach ($section['fields'] ?? [] as $field) {
                if (is_array($field) && isset($field['name'])) {
                    $flat[] = $field['name'];
                }
            }
        }

        return [$flat, $repeatable];
    }

    public function attributes(): array
    {
        return [
            'schema' => 'form layout',
            'settings.fee.amount' => 'fee amount',
            'settings.identity.name' => 'name question',
            'settings.identity.email' => 'email question',
            'settings.identity.phone' => 'phone question',
            'settings.payment.online' => 'card payment switch',
            'settings.payment.staffCodes' => 'staff codes switch',
            'settings.payment.allowFeeCoverage' => 'card fee switch',
            'settings.payment.requireFeeCoverage' => 'required card fee switch',
            'settings.payment.officePayment' => 'pay the office switch',
            'settings.payment.officeInstructions' => 'office payment instructions',
            'settings.fee.countTiers' => 'prices by number of entries',
            'settings.fee.countTiers.*.min' => 'number of entries',
            'settings.fee.countTiers.*.amount' => 'price',
            'settings.fee.countTiers.*.label' => 'price label',
            'settings.payment.eventDate' => 'event date',
            'settings.whatsappUrl' => 'WhatsApp group link',
            'settings.whatsappLabel' => 'WhatsApp button label',
        ];
    }

    public function messages(): array
    {
        return [
            'settings.whatsappUrl.regex' => 'The WhatsApp group link must be a WhatsApp invite link starting https://chat.whatsapp.com/.',
        ];
    }

    /** Exposed so the controller and the update request agree on the vocabulary. */
    public static function fieldTypes(): array
    {
        return FormSchema::FIELD_TYPES;
    }
}
