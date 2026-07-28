<?php

namespace App\Support;

use App\Models\Form;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
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
    ];

    /** Types whose value must be one of the field's declared options. */
    private const CHOICE_TYPES = ['select', 'radio'];

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
            }
        }

        $validator = Validator::make($data, $rules, [], $attributes);

        $this->applyConditionalRequirements($validator, $data);

        return $validator;
    }

    /**
     * @param  array<string,mixed>  $field
     * @return array<int,string>
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
                if ($values !== []) {
                    $rules[] = 'in:' . implode(',', $values);
                }
                break;

            case 'checkboxGroup':
                $rules[] = 'array';
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
     *
     * @return array<string,array<int,string>>
     */
    public function memberRules(): array
    {
        $rules = [];

        foreach ($this->allFields() as [$sectionId, $repeatable, $field]) {
            if (($field['type'] ?? null) !== 'checkboxGroup') {
                continue;
            }

            $values = $this->optionValues($field);
            if ($values === []) {
                continue;
            }

            $key = $repeatable
                ? $sectionId . '.*.' . $field['name'] . '.*'
                : $field['name'] . '.*';

            $rules[$key] = ['string', 'in:' . implode(',', $values)];
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

    /** @return array<int,string> */
    private function optionValues(array $field): array
    {
        $options = $field['options'] ?? [];

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
     * @param  array<string,mixed>  $data
     */
    public function amountDue(array $data): ?float
    {
        $fee = $this->form->feeRule();

        if ($fee === null) {
            return null;
        }

        if ($fee['perEntryOfSection'] === null) {
            return $fee['amount'];
        }

        $rows = Arr::get($data, $fee['perEntryOfSection'], []);
        $count = is_array($rows) ? count($rows) : 0;

        return round($fee['amount'] * $count, 2);
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

        $read = function (?string $path) use ($data): ?string {
            if ($path === null) {
                return null;
            }
            $value = Arr::get($data, $path);

            return is_scalar($value) && $value !== '' ? mb_substr((string) $value, 0, 255) : null;
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
