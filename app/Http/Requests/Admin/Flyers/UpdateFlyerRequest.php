<?php

namespace App\Http\Requests\Admin\Flyers;

use App\Models\Flyer;
use App\Models\FlyerTemplate;

/**
 * Same shape as StoreFlyerRequest with every field optional, so the Studio can save
 * just the title, or just one edited slot, without resending the whole flyer.
 *
 * Extends the store request so the slot rules — unknown-slot rejection, lengths, list
 * shape — stay in one place: content that is valid to create must remain valid to edit.
 */
class UpdateFlyerRequest extends StoreFlyerRequest
{
    public function rules(): array
    {
        return [
            // Changing the design invalidates the old slot values, so the new content
            // has to come with it rather than being validated against a design the
            // flyer no longer uses. `content` carries no `sometimes`: that flag is
            // checked for the whole attribute, so it would suppress the required_with
            // in exactly the case it exists to catch.
            'flyer_template_id' => 'sometimes|integer',
            'content' => 'required_with:flyer_template_id|array',

            'title' => 'sometimes|required|string|max:150',

            'palette' => 'nullable|array|max:40',
            'palette.grad' => 'sometimes|array|max:8',
            'palette.grad.*' => 'string|max:64',
        ];
    }

    public function withValidator($validator): void
    {
        // Nothing posted to check against the design — the stored content is already
        // known-valid, so a title-only save must not re-litigate it.
        if (! is_array($this->input('content'))) {
            return;
        }

        parent::withValidator($validator);
    }

    /**
     * On a partial save the design usually isn't resent, so fall back to the one the
     * flyer already uses. The lookup is tenant-scoped; a flyer belonging to another
     * masjid resolves to null here and to a 404 in the controller, which is the answer
     * that should reach the caller.
     */
    protected function templateForValidation($validator): ?FlyerTemplate
    {
        if ($this->has('flyer_template_id')) {
            return parent::templateForValidation($validator);
        }

        $flyer = Flyer::find((int) $this->route('flyer_id'));

        return $this->resolvedTemplate = $flyer?->template;
    }
}
