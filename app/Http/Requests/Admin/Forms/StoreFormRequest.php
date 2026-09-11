<?php

namespace App\Http\Requests\Admin\Forms;

use App\Http\Requests\BaseFormRequest;
use App\Models\Form;
use App\Rules\TierCutoff;
use App\Rules\ValidFormSchema;
use App\Support\FormPayment;
use App\Support\FormSchema;
use Illuminate\Validation\Rule;

class StoreFormRequest extends BaseFormRequest
{
    /** The `settings.payment` switches (DECISIONS.md 2026-09-11), coerced alike on every door. */
    public const PAYMENT_FLAGS = ['online', 'staffCodes', 'allowFeeCoverage'];

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
            'settings.notifyEmails.*' => 'email:rfc',
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

            // Payment (DECISIONS.md 2026-09-11). Absent means off, which is every
            // form written before the festival. Each switch is a real boolean by
            // the time these run — coercePaymentFlags() reads a form-encoded
            // "true" first, on POST, PUT and form:import alike — and turning one
            // on puts the fee under the stricter rules in crossCheck().
            'settings.payment' => 'nullable|array',
            'settings.payment.online' => 'nullable|boolean',
            'settings.payment.staffCodes' => 'nullable|boolean',
            'settings.payment.allowFeeCoverage' => 'nullable|boolean',
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

        // A key already refused above keeps its first, more specific message.
        foreach (self::paymentProblems($schema, $settings) as $field => $message) {
            $problems[$field] ??= $message;
        }

        return $problems;
    }

    /**
     * NEVER FREE BY ACCIDENT (festival brief, blocker 1): what a form must be
     * before it may take money — card payment (`payment.online`) or cash by staff
     * code (`payment.staffCodes`). With either switch on:
     *
     *  1. The fee is charged per entry of a repeatable section that DEMANDS at
     *     least one entry. FormSchema::validator() adds a minimum-rows rule only
     *     for minEntries > 0 and the renderer's minimum is client-side only, so
     *     otherwise an empty attendee list posts straight past both, and
     *     FormSchema::amountDue() multiplies the price by zero. A flat fee is not
     *     allowed on a paying form: the festival charges per attendee (owner,
     *     2026-09-10), and one per-entry rule is what the door counts bracelets by.
     *  2. EVERY price — the flat amount and each tier, not only the tier in force
     *     today — is at least Stripe's 50¢ and a whole number of cents. A $0
     *     early-bird tier would make the form free until its cut-off; 12.345 has
     *     no honest cent value (FormPayment::wholeMinor()).
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

        if (! $online && ! self::switchedOn($payment['staffCodes'] ?? null)) {
            return [];
        }

        $fee = is_array($settings['fee'] ?? null) ? $settings['fee'] : [];
        $problems = [];

        $perEntry = $fee['perEntryOfSection'] ?? null;

        if ($perEntry === null || (is_string($perEntry) && trim($perEntry) === '')) {
            $problems['settings.fee.perEntryOfSection'] =
                'A form that takes payment must charge its fee per entry of a repeatable section (for example, per attendee).';
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

        if ($prices === []) {
            $problems['settings.fee'] = 'A form that takes payment needs a price.';
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
