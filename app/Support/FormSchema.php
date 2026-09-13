<?php

namespace App\Support;

use App\Models\Form;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Turns a Form's stored schema into server-side validation, and reads submitted data
 * back out of it.
 *
 * The public submit endpoint derives every rule from HERE rather than trusting
 * anything the browser sends. The renderer validates the same schema client-side for
 * UX, but that is a convenience — this class is the enforcement. A submission that
 * skips a required field, invents a select option, or sends 400 attendees to a form
 * capped at 12 is rejected here.
 */
class FormSchema
{
    /**
     * Field types the builder may emit and the renderer knows how to draw.
     * Anything outside this list is rejected when a form is saved, so the renderer
     * can never meet a type it has no branch for.
     */
    public const FIELD_TYPES = [
        'text',
        'email',
        'tel',
        'number',
        'date',
        'textarea',
        'select',
        'radio',
        'checkbox',
        'checkboxGroup',
        'file',
    ];

    /** Types whose value must be one of the field's declared options. */
    private const CHOICE_TYPES = ['select', 'radio'];

    /**
     * Uploads arrive in their own top-level bag, keyed by field name:
     *
     *   POST multipart …  data[registrantName]=Amal   files[resume]=<binary>
     *
     * They are NOT nested inside `data`, because `data` is a JSON object of scalar
     * answers and a multipart body cannot carry both shapes under one key —
     * `$request->input('data')` never contains files. Keeping them apart is what
     * lets a form WITHOUT file fields post exactly the JSON it always did.
     */
    public const UPLOAD_KEY = 'files';

    /**
     * The live offer set of each options source, read once per validation so one
     * submission is checked against one calendar (FormOptionSources).
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private array $offered = [];

    public function __construct(private readonly Form $form)
    {
    }

    public static function for(Form $form): self
    {
        return new self($form);
    }

    // ------------------------------------------------------------------ validation

    /**
     * Build a validator for a submitted `data` payload.
     *
     * Keys are field names for flat sections, and `<sectionId>.*.<fieldName>` for the
     * rows of a repeatable section.
     *
     * @param  array<string,mixed>  $data
     */
    public function validator(array $data): ValidatorInstance
    {
        $rules = [];
        $attributes = [];
        $messages = [];

        foreach ($this->form->sections() as $section) {
            $sectionId = $section['id'] ?? null;
            $fields = $section['fields'] ?? [];

            if (! is_array($fields)) {
                continue;
            }

            if (! empty($section['repeatable']) && $sectionId) {
                // The rows themselves: at least minEntries, at most maxEntries.
                $min = max(0, (int) ($section['minEntries'] ?? 0));
                $max = (int) ($section['maxEntries'] ?? 0);

                $rowRules = ['present', 'array'];
                if ($min > 0) {
                    $rowRules[] = 'min:' . $min;
                }
                if ($max > 0) {
                    $rowRules[] = 'max:' . $max;
                }

                $rules[$sectionId] = $rowRules;
                $attributes[$sectionId] = $section['title'] ?? $sectionId;

                foreach ($fields as $field) {
                    if (! isset($field['name'])) {
                        continue;
                    }
                    $key = $sectionId . '.*.' . $field['name'];
                    $rules[$key] = $this->rulesForField($field);
                    $attributes[$key] = $field['label'] ?? $field['name'];
                }

                continue;
            }

            foreach ($fields as $field) {
                if (! isset($field['name'])) {
                    continue;
                }
                $rules[$field['name']] = $this->rulesForField($field);
                $attributes[$field['name']] = $field['label'] ?? $field['name'];

                // A required calendar question nobody CAN answer says why, rather
                // than "is required" to a family looking at no choices.
                if ($why = $this->unanswerable($field)) {
                    $messages[$field['name'].'.required'] = $why;
                }
            }
        }

        // A checkboxGroup's rule above only says "an array"; its MEMBERS must each be
        // one of the declared options, or any value a client sends is stored.
        foreach ($this->memberRules() as $key => $memberRules) {
            $rules[$key] = $memberRules;
            $attributes[$key] = $attributes[substr($key, 0, -2)] ?? $key;
            $messages[$key.'.distinct'] = 'Each choice can be picked only once.';
        }

        $validator = Validator::make($data, $rules, $messages, $attributes);

        $this->applyConditionalRequirements($validator, $data);

        return $validator;
    }

    /**
     * @param  array<string,mixed>  $field
     * @return array<int,mixed>
     */
    private function rulesForField(array $field): array
    {
        $type = $field['type'] ?? 'text';
        $required = ! empty($field['required']);

        $rules = [];

        // A required checkbox means "must be ticked" (waiver, code of conduct), which
        // is `accepted`, not `required` — `required` passes on boolean false.
        if ($type === 'checkbox') {
            $rules[] = $required ? 'accepted' : 'nullable';
            $rules[] = 'boolean';

            return $rules;
        }

        $rules[] = $required ? 'required' : 'nullable';

        switch ($type) {
            case 'file':
                // Presence only. WHAT may be uploaded — allowed types and the size
                // ceiling — is settled at the request boundary in
                // SubmitFormResponseRequest from config('forms.attachments'), so
                // one form's schema can never widen what the server accepts.
                $rules[] = 'file';
                break;

            case 'email':
                $rules[] = 'email:rfc';
                $rules[] = 'max:255';
                break;

            case 'tel':
                $rules[] = 'string';
                $rules[] = 'max:40';
                break;

            case 'number':
                $rules[] = 'numeric';
                if (isset($field['min'])) {
                    $rules[] = 'min:' . (float) $field['min'];
                }
                if (isset($field['max'])) {
                    $rules[] = 'max:' . (float) $field['max'];
                }
                break;

            case 'date':
                $rules[] = 'date';
                break;

            case 'textarea':
                $rules[] = 'string';
                $rules[] = 'max:5000';
                break;

            case 'select':
            case 'radio':
                $rules[] = 'string';
                $values = $this->optionValues($field);
                if (FormOptionSources::isSourced($field)) {
                    // Checked against the LIVE set even when it is EMPTY: an empty
                    // set must refuse every answer, never switch the check off.
                    $rules[] = FormOptionSources::rule($values);
                } elseif ($values !== []) {
                    $rules[] = 'in:' . implode(',', $values);
                }
                break;

            case 'checkboxGroup':
                $rules[] = 'array';
                if ($count = $this->selectionCountRule($field)) {
                    $rules[] = $count;
                }
                break;

            case 'text':
            default:
                $rules[] = 'string';
                $rules[] = 'max:500';
                break;
        }

        return $rules;
    }

    /**
     * checkboxGroup validates its MEMBERS, not the array, so it needs its own rule key.
     * Rule::in, not an 'in:' string, so an option value with a comma stays one value.
     *
     * @return array<string,array<int,mixed>>
     */
    public function memberRules(): array
    {
        $rules = [];

        foreach ($this->allFields() as [$sectionId, $repeatable, $field]) {
            if (($field['type'] ?? null) !== 'checkboxGroup') {
                continue;
            }

            $values = $this->optionValues($field);
            $sourced = FormOptionSources::isSourced($field);
            if ($values === [] && ! $sourced) {
                continue;
            }

            $key = $repeatable
                ? $sectionId . '.*.' . $field['name'] . '.*'
                : $field['name'] . '.*';

            // `distinct`: the same choice twice is not two choices, and would
            // otherwise satisfy "pick exactly 2" with one Sunday.
            $rules[$key] = ['string', 'distinct', $sourced ? FormOptionSources::rule($values) : Rule::in($values)];
        }

        return $rules;
    }

    /**
     * Conditional requirements that Laravel's rule strings cannot express.
     *
     * Today there is one rule shape, generalised from the camp form's "guardian name is
     * required if any attendee is under 18":
     *
     *   requiredIf: { rule: "anyEntryUnder", section: "attendees", field: "age", value: 18 }
     *
     * It reads a repeatable section's rows and requires the field when ANY row's
     * numeric value falls below the threshold.
     *
     * @param  array<string,mixed>  $data
     */
    private function applyConditionalRequirements(ValidatorInstance $validator, array $data): void
    {
        $conditionals = [];

        foreach ($this->allFields() as [$sectionId, $repeatable, $field]) {
            if (empty($field['requiredIf']) || $repeatable) {
                continue;
            }
            $conditionals[] = $field;
        }

        if ($conditionals === []) {
            return;
        }

        $validator->after(function (ValidatorInstance $v) use ($conditionals, $data) {
            foreach ($conditionals as $field) {
                if (! $this->conditionalApplies($field['requiredIf'], $data)) {
                    continue;
                }

                $value = Arr::get($data, $field['name']);

                if ($value === null || $value === '' || $value === false) {
                    $label = $field['label'] ?? $field['name'];
                    $v->errors()->add(
                        $field['name'],
                        $this->conditionalMessage($field['requiredIf'], $label)
                    );
                }
            }
        });
    }

    /**
     * @param  array<string,mixed>  $rule
     * @param  array<string,mixed>  $data
     */
    private function conditionalApplies($rule, array $data): bool
    {
        if (! is_array($rule) || ($rule['rule'] ?? null) !== 'anyEntryUnder') {
            return false;
        }

        $rows = Arr::get($data, $rule['section'] ?? '', []);

        if (! is_array($rows)) {
            return false;
        }

        $field = $rule['field'] ?? null;
        $threshold = $rule['value'] ?? null;

        if ($field === null || ! is_numeric($threshold)) {
            return false;
        }

        foreach ($rows as $row) {
            $value = is_array($row) ? ($row[$field] ?? null) : null;
            if (is_numeric($value) && (float) $value < (float) $threshold) {
                return true;
            }
        }

        return false;
    }

    private function conditionalMessage($rule, string $label): string
    {
        $threshold = $rule['value'] ?? null;

        return sprintf(
            '%s is required when any entry is under %s.',
            $label,
            is_numeric($threshold) ? (string) (int) $threshold : 'the threshold'
        );
    }

    // -------------------------------------------------------------------- reading

    /**
     * Every field in the schema, flattened.
     *
     * @return array<int,array{0:?string,1:bool,2:array<string,mixed>}> [sectionId, isRepeatable, field]
     */
    public function allFields(): array
    {
        $out = [];

        foreach ($this->form->sections() as $section) {
            $sectionId = $section['id'] ?? null;
            $repeatable = ! empty($section['repeatable']);

            foreach ($section['fields'] ?? [] as $field) {
                if (is_array($field) && isset($field['name'])) {
                    $out[] = [$sectionId, $repeatable, $field];
                }
            }
        }

        return $out;
    }

    /**
     * The `file` questions this form declares, keyed by field name.
     *
     * Empty for every form built before T-004, which is what makes the whole
     * upload path inert for them: no file fields means no uploads are read, no
     * attachment rows are written, and nothing about the submission changes.
     *
     * @return array<string,array<string,mixed>>
     */
    public function fileFields(): array
    {
        $out = [];

        foreach ($this->allFields() as [, $repeatable, $field]) {
            // A repeatable section is rejected at save time if it contains a file
            // question (App\Rules\ValidFormSchema); skipping here too means a
            // schema stored before that rule existed still cannot reach the disk.
            if ($repeatable || ($field['type'] ?? null) !== 'file') {
                continue;
            }

            $out[$field['name']] = $field;
        }

        return $out;
    }

    /**
     * The uploads this form actually asked for, pulled out of the request's
     * `files` bag and keyed by field name.
     *
     * Anything the schema does not declare is dropped, exactly as `only()` drops
     * undeclared answers — a caller cannot make us write a file for a question
     * that does not exist.
     *
     * @param  mixed  $files  whatever came in under FormSchema::UPLOAD_KEY
     * @return array<string,\Illuminate\Http\UploadedFile>
     */
    public function uploads($files): array
    {
        if (! is_array($files)) {
            return [];
        }

        $out = [];

        foreach (array_keys($this->fileFields()) as $name) {
            $file = $files[$name] ?? null;

            if ($file instanceof \Illuminate\Http\UploadedFile) {
                $out[$name] = $file;
            }
        }

        return $out;
    }

    /**
     * How many a choose-any question asks for: [minSelections, maxSelections],
     * each null when unset. ValidFormSchema has already refused anything but
     * whole numbers of at least 1, on a checkboxGroup, with min <= max.
     *
     * @param  array<string,mixed>  $field
     * @return array{0:?int,1:?int}
     */
    public static function selectionBounds(array $field): array
    {
        $read = fn ($v): ?int => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;

        return [$read($field['minSelections'] ?? null), $read($field['maxSelections'] ?? null)];
    }

    /**
     * "Pick exactly 2 Sundays." Counts a NON-EMPTY answer only: whether the
     * question may be left blank is `required`'s decision, as for every field.
     *
     * A calendar question with fewer days open than it asks for cannot be
     * answered correctly by anyone, so it says that instead; an EMPTY offer set
     * is the member rule's to report (FormOptionSources::NONE_OPEN).
     *
     * @param  array<string,mixed>  $field
     */
    private function selectionCountRule(array $field): ?Closure
    {
        [$min, $max] = self::selectionBounds($field);

        if ($min === null && $max === null) {
            return null;
        }

        $offered = null;
        $singular = 'option';

        if (FormOptionSources::isSourced($field)) {
            $values = $this->optionValues($field);
            $offered = count($values);
            $singular = isset($values[0]) ? (SchoolCalendar::day($values[0])?->format('l') ?? 'day') : 'day';
        }

        $noun = fn (int $n): string => $n === 1 ? $singular : $singular.'s';

        return function (string $attribute, mixed $value, Closure $fail) use ($min, $max, $offered, $noun): void {
            $picked = is_array($value) ? count($value) : 0;

            if ($picked === 0 || $offered === 0) {
                return;
            }

            if ($offered !== null && $min !== null && $offered < $min) {
                $fail(FormOptionSources::NOT_ENOUGH_OPEN);
            } elseif ($min !== null && $min === $max && $picked !== $min) {
                $fail(sprintf('Pick exactly %d %s.', $min, $noun($min)));
            } elseif ($min !== null && $picked < $min) {
                $fail(sprintf('Pick at least %d %s.', $min, $noun($min)));
            } elseif ($max !== null && $picked > $max) {
                $fail(sprintf('Pick no more than %d %s.', $max, $noun($max)));
            }
        };
    }

    /**
     * Why a calendar question cannot be answered at all right now, or null.
     *
     * @param  array<string,mixed>  $field
     */
    private function unanswerable(array $field): ?string
    {
        if (! FormOptionSources::isSourced($field)) {
            return null;
        }

        $offered = count($this->optionValues($field));
        [$min] = self::selectionBounds($field);

        return match (true) {
            $offered === 0 => FormOptionSources::NONE_OPEN,
            $min !== null && $offered < $min => FormOptionSources::NOT_ENOUGH_OPEN,
            default => null,
        };
    }

    /**
     * The values a choice field accepts. A calendar-sourced field reads the
     * live OFFER set — open meeting days after today — and never stored options.
     *
     * @return array<int,string>
     */
    private function optionValues(array $field): array
    {
        if (FormOptionSources::isSourced($field)) {
            $source = is_scalar($field['optionsSource']) ? (string) $field['optionsSource'] : '';
            $options = $this->offered[$source] ??= FormOptionSources::resolve($this->form, $field, FormOptionSources::OFFER);
        } else {
            $options = $field['options'] ?? [];
        }

        if (! is_array($options)) {
            return [];
        }

        return collect($options)
            ->pluck('value')
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->values()
            ->all();
    }

    /**
     * How many entries this submission represents — the repeatable section's row count,
     * or 1 for a form without one. Drives capacity accounting and the fee total.
     *
     * @param  array<string,mixed>  $data
     */
    public function entryCount(array $data): int
    {
        $section = $this->form->repeatableSection();

        if (! $section || ! isset($section['id'])) {
            return 1;
        }

        $rows = Arr::get($data, $section['id'], []);

        return is_array($rows) ? max(1, count($rows)) : 1;
    }

    /**
     * The amount owed, computed at submission time and then stored, so a later price
     * change does not restate what someone already agreed to pay.
     *
     * Read through Form::priceFor(), the resolver FormPayment::quote() prices the cents
     * snapshot from, so the decimal and the cents never count differently — a family
     * of three on a form priced by the number of children stores 250.00, never
     * 100.00 × 3.
     *
     * Null when the form charges nothing, or when its price cannot be resolved for
     * this submission; a form that takes payment refuses the submission first.
     *
     * @param  array<string,mixed>  $data
     */
    public function amountDue(array $data): ?float
    {
        $price = $this->form->priceFor($data);

        if ($price === null) {
            return null;
        }

        // A flat fee: byte-for-byte what this always returned.
        if ($price['fee']['perEntryOfSection'] === null) {
            return $price['fee']['amount'];
        }

        return round($price['unit'] * $price['quantity'], 2);
    }

    /**
     * Pull the three searchable identity values out of a submission, per the form's
     * declared identity map.
     *
     * @param  array<string,mixed>  $data
     * @return array{respondent_name: ?string, respondent_email: ?string, respondent_phone: ?string}
     */
    public function identity(array $data): array
    {
        $map = $this->form->identityMap();

        // A slot may name ONE field or SEVERAL. Several matters for a form that splits a
        // person's name into first and last: the admin list has to show and search
        // "Amal Yusuf", not just "Amal", so the parts are joined here rather than the
        // list being made to understand a composite.
        $read = function ($path) use ($data): ?string {
            if ($path === null) {
                return null;
            }

            $parts = collect(is_array($path) ? $path : [$path])
                ->map(fn ($p) => Arr::get($data, $p))
                ->filter(fn ($v) => is_scalar($v) && trim((string) $v) !== '')
                ->map(fn ($v) => trim((string) $v));

            if ($parts->isEmpty()) {
                return null;
            }

            return mb_substr($parts->implode(' '), 0, 255);
        };

        return [
            'respondent_name' => $read($map['name']),
            'respondent_email' => $read($map['email']),
            'respondent_phone' => $read($map['phone']),
        ];
    }

    /**
     * Drop anything the schema does not declare.
     *
     * Without this, a caller could post arbitrary extra keys and we would store them
     * verbatim in `data` and later render them in the admin table and hand them to the
     * assistant. Only declared fields survive.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function only(array $data): array
    {
        $clean = [];

        foreach ($this->form->sections() as $section) {
            $sectionId = $section['id'] ?? null;
            $names = collect($section['fields'] ?? [])
                // File fields are excluded: their submitted value is an
                // UploadedFile, and json_encoding one into `data` would store a
                // temp path instead of a document. The controller writes the
                // respondent's ORIGINAL FILENAME back under this key once the
                // upload is on disk, so the admin table still has a cell to show.
                ->reject(fn ($field) => is_array($field) && ($field['type'] ?? null) === 'file')
                ->pluck('name')
                ->filter()
                ->all();

            if (! empty($section['repeatable']) && $sectionId) {
                $rows = Arr::get($data, $sectionId, []);

                if (! is_array($rows)) {
                    continue;
                }

                $clean[$sectionId] = collect($rows)
                    ->map(fn ($row) => is_array($row) ? Arr::only($row, $names) : [])
                    ->values()
                    ->all();

                continue;
            }

            foreach ($names as $name) {
                if (array_key_exists($name, $data)) {
                    $clean[$name] = $data[$name];
                }
            }
        }

        return $clean;
    }
}
