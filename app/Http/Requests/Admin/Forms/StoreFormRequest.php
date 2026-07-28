<?php

namespace App\Http\Requests\Admin\Forms;

use App\Http\Requests\BaseFormRequest;
use App\Rules\ValidFormSchema;
use App\Support\FormSchema;
use Illuminate\Validation\Rule;

class StoreFormRequest extends BaseFormRequest
{
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

        if ($this->has('is_active')) {
            $merge['is_active'] = filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',

            // Slug is unique per masjid, mirroring the pages table. The masjid comes
            // from the route, not the payload, so tenancy cannot be spoofed here.
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('forms', 'slug')
                    ->where('masjid_id', (int) $this->route('masjid_id'))
                    ->whereNull('deleted_at'),
            ],

            'schema' => ['required', 'array', new ValidFormSchema()],

            'settings' => 'nullable|array',
            'settings.submitButtonLabel' => 'nullable|string|max:80',
            'settings.successTitle' => 'nullable|string|max:255',
            'settings.successBody' => 'nullable|string|max:5000',
            'settings.successNextSteps' => 'nullable|array|max:10',
            'settings.successNextSteps.*' => 'string|max:255',
            'settings.notifyEmails' => 'nullable|array|max:10',
            'settings.notifyEmails.*' => 'email:rfc',
            'settings.intro' => 'nullable|string|max:20000',

            // Which schema field feeds each searchable column on form_responses.
            'settings.identity' => 'nullable|array',
            'settings.identity.name' => 'nullable|string|max:255',
            'settings.identity.email' => 'nullable|string|max:255',
            'settings.identity.phone' => 'nullable|string|max:255',

            'settings.fee' => 'nullable|array',
            'settings.fee.amount' => 'required_with:settings.fee|numeric|min:0|max:1000000',
            'settings.fee.currency' => 'nullable|string|size:3',
            'settings.fee.perEntryOfSection' => 'nullable|string|max:255',

            'is_active' => 'boolean',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date|after_or_equal:opens_at',
            'capacity' => 'nullable|integer|min:1|max:1000000',
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

            [$flatNames, $repeatableIds] = $this->schemaNames($schema);

            foreach (['name', 'email', 'phone'] as $slot) {
                $field = $this->input("settings.identity.{$slot}");

                if ($field !== null && $field !== '' && ! in_array($field, $flatNames, true)) {
                    $validator->errors()->add(
                        "settings.identity.{$slot}",
                        "\"{$field}\" is not a question in this form, so it cannot be used for the {$slot}."
                    );
                }
            }

            $perEntry = $this->input('settings.fee.perEntryOfSection');

            if ($perEntry !== null && $perEntry !== '' && ! in_array($perEntry, $repeatableIds, true)) {
                $validator->errors()->add(
                    'settings.fee.perEntryOfSection',
                    "\"{$perEntry}\" is not a repeatable section, so the fee cannot be charged per entry of it."
                );
            }
        });
    }

    /**
     * @return array{0:array<int,string>,1:array<int,string>} [flat field names, repeatable section ids]
     */
    private function schemaNames(array $schema): array
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
        ];
    }

    /** Exposed so the controller and the update request agree on the vocabulary. */
    public static function fieldTypes(): array
    {
        return FormSchema::FIELD_TYPES;
    }
}
