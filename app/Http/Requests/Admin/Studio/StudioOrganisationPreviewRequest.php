<?php

namespace App\Http\Requests\Admin\Studio;

use App\Http\Requests\BaseFormRequest;

/**
 * POST /api/admin/studio/organisations/{id}/preview: the changes Studio is
 * considering for a live organisation, previewed and never written (S9).
 * `brand` holds the four colours; `capabilities` holds key => boolean.
 *
 * Booleans are coerced here, server side, because the SPA's axios default is
 * form-encoded (.claude/rules/shipping.md); FILTER_NULL_ON_FAILURE so
 * nonsense fails rather than reading as false. Which keys may be previewed is
 * the capability writer's rule (PreviewInput::fromMasjid asks
 * CapabilityWriter::assertWritable), so the preview and the save agree.
 */
class StudioOrganisationPreviewRequest extends BaseFormRequest
{
    public const COLOURS = ['primary_color', 'secondary_color', 'accent_color', 'background_color'];

    protected function prepareForValidation(): void
    {
        $sent = $this->input('capabilities');

        if (is_array($sent)) {
            $this->merge([
                'capabilities' => array_map(
                    fn ($value) => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                    $sent
                ),
            ]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'brand' => ['sometimes', 'array:' . implode(',', self::COLOURS)],
            'capabilities' => ['sometimes', 'array', 'max:' . count((array) config('capabilities'))],
            'capabilities.*' => ['required', 'boolean'],
        ];

        foreach (self::COLOURS as $colour) {
            $rules["brand.{$colour}"] = ['required_with:brand', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        }

        return $rules;
    }

    /** @return array{brand?: array<string, string>, capabilities?: array<string, bool>} */
    public function overrides(): array
    {
        return array_filter([
            'brand' => $this->validated('brand'),
            'capabilities' => $this->validated('capabilities'),
        ], fn ($value) => is_array($value) && $value !== []);
    }
}
