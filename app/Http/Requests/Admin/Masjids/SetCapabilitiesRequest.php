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
 * being read as false, and null or empty is never coerced at all (coerce()).
 * CapabilityWriter::apply() takes real booleans only.
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
                fn ($value) => self::coerce($value),
                $sent
            ),
        ]);
    }

    /**
     * A non-null scalar is read as a boolean when it is one ("1", "0", "true",
     * "false", 1, 0 …) and becomes null otherwise, which `boolean` refuses.
     * Null itself (JSON null, or a form `capabilities[x]=` after
     * ConvertEmptyStringsToNull) and arrays are left as they came, so
     * `required|boolean` refuses them: filter_var reads null and '' as FALSE even
     * with FILTER_NULL_ON_FAILURE, which would store an OFF nobody chose. The
     * single switch refuses the same input ("The enabled field is required.").
     */
    private static function coerce(mixed $value): mixed
    {
        if (is_bool($value) || $value === null || ! is_scalar($value)) {
            return $value;
        }

        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function rules(): array
    {
        return [
            'capabilities' => ['required', 'array', 'min:1', 'max:' . count((array) config('capabilities'))],
            'capabilities.*' => ['required', 'boolean'],
        ];
    }
}
