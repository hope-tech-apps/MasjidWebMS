<?php

namespace App\Http\Requests\Admin\Masjids;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates the SuperAdmin bulk capability write (PATCH .../capabilities),
 * `capabilities[<key>] = 1|0`, form-encoded or JSON.
 *
 * Each value is coerced here, server side, because the SPA's axios default is
 * form-encoded and a browser holding a cached bundle can send the strings
 * "true"/"false", which the `boolean` rule refuses (.claude/rules/shipping.md).
 * FILTER_NULL_ON_FAILURE so genuine nonsense still fails validation rather than
 * being read as false. CapabilityWriter::apply() takes real booleans only.
 *
 * Which keys may be written is the writer's call, not this request's
 * (CapabilityWriter::assertWritable), so the single switch and this answer an
 * unknown or column-backed key with the same sentence.
 */
class SetCapabilitiesRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $sent = $this->input('capabilities');

        if (! is_array($sent)) {
            return;
        }

        $this->merge([
            'capabilities' => array_map(
                fn ($value) => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                $sent
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'capabilities' => ['required', 'array', 'min:1', 'max:' . count((array) config('capabilities'))],
            'capabilities.*' => ['required', 'boolean'],
        ];
    }
}
