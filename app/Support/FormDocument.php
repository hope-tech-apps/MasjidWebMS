<?php

namespace App\Support;

use App\Http\Requests\Admin\Forms\StoreFormRequest;
use App\Models\Form;
use App\Models\Masjid;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\TransformsRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * =============================================================================
 * A FORM DEFINITION THAT HAS BEEN THROUGH THE DOOR — the only shape a `forms`
 * row may be written from, whichever door wrote it.
 * =============================================================================
 *
 * ## The class of bug this exists to end
 *
 * Seven rounds of review each fixed a list of findings and the next reviewer
 * found more of the SAME class: A RULE ESTABLISHED AT ONE DOOR AND BELIEVED AT
 * ALL OF THEM. `ImportFormCommand`'s docblock promised twice that a file
 * "cannot introduce a form the builder would have rejected" and was measurably
 * false both times; round seven then normalised ONE KEY (`fee.tiers.*.until`)
 * and the equivalence test only ever mutated that same key, so it proved
 * nothing about the rest of the document.
 *
 * The generalisation the reviewer wrote down, and this class implements:
 * NORMALISE THE WHOLE DOCUMENT THE WAY THE HTTP STACK DOES, NOT ONE KEY.
 *
 * ## Why it normalises by RUNNING the middleware rather than reproducing it
 *
 * `TrimStrings` + `ConvertEmptyStringsToNull` are global middleware, so on
 * `POST`/`PUT` every string in the document is trimmed and every empty one is
 * null before any rule can see it. A file, a config template and a seeder pass
 * through no middleware at all. Round seven's fix was a hand-written
 * `TierCutoff::normalise()` — a transcription, applied to one key — and a
 * transcription of a rule is the thing this whole review keeps finding.
 *
 * So this does not transcribe. `transformers()` asks the running kernel for its
 * OWN global middleware list and keeps the `TransformsRequest` subclasses, in
 * their real order; `normalise()` puts the document through them. There is one
 * implementation of "what the HTTP stack does to a document" and it is the HTTP
 * stack's. `TrimStrings::except()` / `::skipWhen()` are static registrations, so
 * even those are honoured. Add a third transformer to the app and every door
 * gets it in the same edit, with nothing to remember.
 *
 * Measured before this, one document, five keys, four doors — the four shapes
 * that reach the database differently depending on which door was used:
 *
 *   capacity ''            POST -> null (unlimited)   import -> 0, and
 *                          `isAtCapacity()` is then TRUE forever: the form
 *                          refuses every submission with "reached capacity"
 *   fee.amount ''          POST -> null (no fee)      import -> (float) '' = 0.0
 *   perEntryOfSection ''   POST -> null (flat fee)    import -> '', and
 *                          `amountDue()` multiplies by a row count of zero —
 *                          THE CAMP IS FREE
 *   closes_at ''           POST -> null (no close)    import -> '' , which the
 *                          datetime cast reads as "now" — registration shut
 *   currency ' USD '       POST -> 'USD', 201         import -> 422 `size:3`
 *   name '  Camp X  '      POST -> 'Camp X'           import -> '  Camp X  '
 *
 * ## Where the rule lives now
 *
 * ONE ruleset (`documentRules()` + `settingsRules()`), ONE cross-check
 * (`crossCheck()`), ONE normaliser (`normalise()`), ONE writer (`writeFor()`).
 * `StoreFormRequest` — and through it `UpdateFormRequest`, and through those the
 * whole HTTP door — calls into this class rather than owning a copy.
 *
 * ## What makes the WRONG usage fail rather than silently skip
 *
 * Two things, deliberately structural rather than remembered:
 *
 *  1. `Form::setAttribute()` runs `normalise()` on every attribute written to
 *     the model. There is no way to set an attribute on an Eloquent model that
 *     does not go through it, so a door written tomorrow that never heard of
 *     this class still stores the same row as the builder. (The query builder's
 *     `insert()` does bypass it — which is why the meta-test scans for that too.)
 *  2. `FormDocument` has a private constructor and no way to hand out a raw
 *     array: `attributes()` can only be reached through `from()`/`tryFrom()`,
 *     both of which validate. Following `App\Support\ContactIdentity`: the
 *     unvalidated shape is not representable, so it cannot be passed by mistake.
 *
 * `FormDoorEquivalenceTest` DISCOVERS the doors from the source tree, the route
 * table and the console kernel and fails when a new one appears, so the sentence
 * above cannot quietly go false a third time.
 */
final class FormDocument
{
    /**
     * @param  array<string,mixed>  $attributes  normalised and validated
     */
    private function __construct(private readonly array $attributes)
    {
    }

    // ---------------------------------------------------------------- normalise

    /**
     * The document as the HTTP stack would have handed it to a controller.
     *
     * Whole-document and recursive, because that is the only version of this
     * statement that is true: `settings.fee.tiers.3.until` is no more special
     * than `capacity`, `name` or `schema.sections.0.fields.2.label`, and every
     * one of them reaches the database differently through a door that is not
     * behind the middleware.
     *
     * @param  array<string,mixed>  $document
     * @return array<string,mixed>
     */
    public static function normalise(array $document): array
    {
        $transformers = self::transformers();

        if ($transformers === []) {
            return $document;
        }

        // A real request carrying the document as its input bag, so the
        // middleware do to it exactly what they do to a POST. Not JSON on
        // purpose: `TransformsRequest::clean()` cleans `$request->request` for a
        // form post and `$request->json()` for a JSON one, and building the
        // parameter bag directly keeps every value's PHP type (null stays null,
        // 140 stays an int) instead of round-tripping through a JSON string.
        $request = Request::create('/__form-document', 'POST', $document);

        $pipeline = array_reduce(
            array_reverse($transformers),
            fn (\Closure $next, string $middleware) => fn (Request $req) => app($middleware)->handle($req, $next),
            fn (Request $req) => $req,
        );

        return $pipeline($request)->all();
    }

    /**
     * One value, normalised as the attribute of that name would be.
     *
     * The key matters: `TrimStrings` skips a configured `except` list by key
     * path, so normalising `['name' => $v]` is what the HTTP stack does to
     * `name` — not what it does to some anonymous string.
     */
    public static function normaliseValue(string $key, mixed $value): mixed
    {
        if (! is_string($value) && ! is_array($value)) {
            return $value;
        }

        return self::normalise([$key => $value])[$key] ?? null;
    }

    /**
     * THE app's own global request transformers, asked of the running kernel
     * rather than listed here.
     *
     * @return array<int,class-string>
     */
    private static function transformers(): array
    {
        $kernel = app(Kernel::class);

        if (! method_exists($kernel, 'getGlobalMiddleware')) {
            return [];
        }

        return array_values(array_filter(
            $kernel->getGlobalMiddleware(),
            fn ($middleware) => is_string($middleware) && is_subclass_of($middleware, TransformsRequest::class),
        ));
    }

    // ----------------------------------------------------------------- the rule

    /**
     * Everything about a form definition except its `slug` and its `settings`.
     *
     * `slug` is excluded because it is the ONE rule the doors legitimately
     * differ on: the admin API refuses a duplicate, `form:import` and
     * `FormTemplates` are idempotent by (masjid_id, slug) and treat an existing
     * one as their update / skip path. Every door still requires the same SHAPE
     * — see `slugRules()`.
     *
     * @return array<string,mixed>
     */
    public static function documentRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'schema' => ['required', 'array', new \App\Rules\ValidFormSchema()],
            'is_active' => 'boolean',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date|after_or_equal:opens_at',
            'capacity' => 'nullable|integer|min:1|max:1000000',
        ];
    }

    /**
     * The `settings` rules — where THE FEE LIVES.
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
            // The whole cut-off contract is stated once in App\Rules\TierCutoff.
            // Loosening it re-opens a $40-per-attendee under-charge; TieredFeeTest
            // pins the read half and FormDoorEquivalenceTest pins that every door
            // applies it.
            'settings.fee.tiers' => 'nullable|array|max:10',
            'settings.fee.tiers.*.amount' => 'required|numeric|min:0|max:1000000',
            'settings.fee.tiers.*.until' => [new \App\Rules\TierCutoff()],
            'settings.fee.tiers.*.label' => 'nullable|string|max:60',
            'settings.fee.currency' => 'nullable|string|size:3',
            'settings.fee.perEntryOfSection' => 'nullable|string|max:255',
        ];
    }

    /**
     * The SHAPE every door requires of a slug. Uniqueness is the one clause the
     * doors differ on and it is added by the door that needs it.
     *
     * @return array<int,string>
     */
    public static function slugRules(): array
    {
        return ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
    }

    /**
     * @return array<string,string>
     */
    public static function attributeNames(): array
    {
        return [
            'schema' => 'form layout',
            'settings.fee.amount' => 'fee amount',
            'settings.identity.name' => 'name question',
            'settings.identity.email' => 'email question',
            'settings.identity.phone' => 'phone question',
        ];
    }

    /**
     * THE cross-check: does every question and section that `settings` names
     * actually exist in `$schema`?
     *
     * A typo here fails SILENTLY at runtime — an unsearchable response list, or
     * a total of zero — which is why it is a refusal and not a warning.
     * Measured, one word, one 200, a camp with a $100-per-attendee fee and two
     * attendees: `amountDue()` 200.0 before, 0.0 after.
     *
     * ## Blank means "not declared", here and in `Form::feeRule()`, and that is
     * ## now a decision rather than an accident
     *
     * `''` skips this check (below) while `FormSchema::amountDue()` used to read
     * it as a section name, look for rows under the key `''`, find none and
     * multiply the fee by zero. The two disagreed about one value, and no door
     * behind the middleware could produce it — which is exactly why nobody saw
     * it. It is settled in the direction the HTTP stack has always settled it:
     * `ConvertEmptyStringsToNull` stores NULL for a cleared box, null means
     * "flat fee", so blank means flat fee everywhere. `Form::feeRule()` now says
     * the same thing for rows written before this class existed. The other
     * direction — refusing blank here — would have been a rule only the doors
     * OUTSIDE the middleware could ever apply, which is the shape this whole
     * round exists to remove.
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
            $declared = self::declared($settings['identity'][$slot] ?? null);

            if ($declared === null) {
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

        $perEntry = self::declared($settings['fee']['perEntryOfSection'] ?? null);

        if ($perEntry !== null && ! in_array($perEntry, $repeatableIds, true)) {
            $label = is_string($perEntry) ? $perEntry : json_encode($perEntry);
            $problems['settings.fee.perEntryOfSection'] =
                "\"{$label}\" is not a repeatable section, so the fee cannot be charged per entry of it.";
        }

        return $problems;
    }

    /**
     * "Was this actually declared?" — null, `''` and whitespace-only are all the
     * absence of a declaration, and every reader of these settings must agree
     * about that or a camp prices differently depending on which one asked.
     *
     * Shared with `Form::feeRule()` so there is one answer.
     */
    public static function declared(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = \Illuminate\Support\Str::trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return $value === [] ? null : $value;
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

    // -------------------------------------------------------------- the doors

    /**
     * Normalise, validate, cross-check. Throws on refusal, so a door that
     * forgets to check cannot write anything.
     *
     * @param  array<string,mixed>  $document
     * @param  array<string,mixed>  $extraRules  the door's own additions — the
     *                                           admin API's slug-uniqueness clause
     *
     * @throws ValidationException
     */
    public static function from(array $document, array $extraRules = []): self
    {
        $errors = null;
        $accepted = self::tryFrom($document, $errors, $extraRules);

        if (! $accepted) {
            throw ValidationException::withMessages($errors);
        }

        return $accepted;
    }

    /**
     * The same door, answering with null instead of an exception — for the
     * console, which reports and exits rather than throwing.
     *
     * @param  array<string,mixed>  $document
     * @param  array<string,array<int,string>>|null  $errors  filled on refusal
     * @param  array<string,mixed>  $extraRules
     */
    public static function tryFrom(array $document, ?array &$errors = null, array $extraRules = []): ?self
    {
        // Editorial comments are allowed in a file and dropped on the way in.
        unset($document['$comment']);

        // `is_active` is coerced exactly as StoreFormRequest::prepareForValidation
        // coerces it, BEFORE the shared rules run. Measured before this existed:
        // the same file with `"is_active": null` imported as a LIVE form and
        // POSTed as a switched-off one — both doors answered success and the two
        // rows disagreed about whether the public could see the form at all.
        // Absent still means live (the documented default).
        if (array_key_exists('is_active', $document)) {
            $document['is_active'] = filter_var($document['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $document = self::normalise($document);

        $validator = Validator::make(
            $document,
            ['slug' => self::slugRules()] + self::documentRules() + self::settingsRules() + $extraRules,
            [],
            self::attributeNames(),
        );

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();

            return null;
        }

        $problems = self::crossCheck(
            (array) ($document['schema'] ?? []),
            (array) ($document['settings'] ?? []),
        );

        if ($problems !== []) {
            $errors = array_map(fn ($message) => [$message], $problems);

            return null;
        }

        return new self([
            'slug' => $document['slug'],
            'name' => $document['name'],
            'description' => $document['description'] ?? null,
            'schema' => $document['schema'],
            'settings' => $document['settings'] ?? null,
            'is_active' => $document['is_active'] ?? true,
            'opens_at' => $document['opens_at'] ?? null,
            'closes_at' => $document['closes_at'] ?? null,
            'capacity' => $document['capacity'] ?? null,
        ]);
    }

    /**
     * The write-ready attributes. Unreachable without having gone through
     * `from()`/`tryFrom()` — there is no other constructor.
     *
     * @return array<string,mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function slug(): string
    {
        return (string) $this->attributes['slug'];
    }

    /**
     * THE WRITE, for every door that is idempotent by (masjid_id, slug) — the
     * importer, the template applier, the demo seeder.
     *
     * Restores a soft-deleted row rather than colliding with the unique index:
     * re-importing a form an admin deleted is a deliberate restore, and it is
     * the reason `form:import` gets to differ from the admin API on `slug` at
     * all.
     */
    public function writeFor(Masjid $masjid, bool $restoreTrashed = true): Form
    {
        $existing = Form::withTrashed()
            ->where('masjid_id', $masjid->id)
            ->where('slug', $this->slug())
            ->first();

        if (! $existing) {
            return Form::create($this->attributes + ['masjid_id' => $masjid->id]);
        }

        if ($existing->trashed() && $restoreTrashed) {
            $existing->restore();
        }

        $existing->update($this->attributes);

        return $existing;
    }

    /**
     * Whether a row already holds this slug for the tenant — live or
     * soft-deleted. `FormTemplates` skips rather than writes in that case.
     */
    public function alreadyExistsFor(Masjid $masjid): bool
    {
        return Form::withTrashed()
            ->where('masjid_id', $masjid->id)
            ->where('slug', $this->slug())
            ->exists();
    }

    /**
     * The admin API's rules, assembled here so `StoreFormRequest` and this class
     * cannot drift. Kept as a method on the request itself for the framework's
     * benefit; this is the list.
     *
     * @return array<string,mixed>
     */
    public static function httpRules(int $masjidId, ?int $ignoreFormId = null): array
    {
        return StoreFormRequest::slugUniquenessRules($masjidId, $ignoreFormId)
            + self::documentRules()
            + self::settingsRules();
    }
}
