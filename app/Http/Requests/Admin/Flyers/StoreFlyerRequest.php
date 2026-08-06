<?php

namespace App\Http\Requests\Admin\Flyers;

use App\Http\Requests\BaseFormRequest;
use App\Models\FlyerTemplate;
use Illuminate\Support\Str;

class StoreFlyerRequest extends BaseFormRequest
{
    /**
     * The template the content was validated against, kept so the controller does not
     * have to resolve it a second time — FlyersController::store hands this straight to
     * the serializer. Null until withValidator() has run, and still null on an update
     * that posted no content, which UpdateFlyerRequest deliberately does not re-check.
     */
    public ?FlyerTemplate $resolvedTemplate = null;

    /**
     * The Studio posts JSON, but the same screen can fall back to FormData, in which
     * case `content` and `palette` arrive as JSON-encoded strings. Decode before
     * rules() sees them, matching StoreFormRequest's handling of `schema`.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['content', 'palette'] as $key) {
            if (is_string($this->input($key))) {
                $merge[$key] = json_decode($this->input($key), true);
            }
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            // Deliberately NOT `exists:flyer_templates,id`: that would happily accept
            // another masjid's private design. The real check is the tenant-scoped
            // lookup in templateForValidation(), which sees shared + own only.
            'flyer_template_id' => 'required|integer',

            'title' => 'required|string|max:150',

            // Slot values, keyed by slot name. Shape is checked against the template's
            // own schema below — this rule only guarantees it is a map.
            'content' => 'required|array',

            // The colours as resolved by palette.js at save time, snapshotted so a
            // rebrand cannot restyle an approved flyer. Extra keys are allowed (the
            // resolver may grow), which is why the size caps matter.
            'palette' => 'nullable|array|max:40',
            'palette.grad' => 'sometimes|array|max:8',
            'palette.grad.*' => 'string|max:64',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $template = $this->templateForValidation($validator);

            if (! $template || ! is_array($this->input('content'))) {
                return;
            }

            $this->validateContentAgainstTemplate($validator, $template, (array) $this->input('content'));
        });
    }

    /**
     * Resolve the design this flyer is being built from, through the tenant-scoped
     * model so a hand-posted id can only ever name a shared (system) template or one
     * this masjid authored. FlyerTemplate widens the tenant scope to "shared OR mine",
     * so find() is both the lookup and the authorization check.
     */
    protected function templateForValidation($validator): ?FlyerTemplate
    {
        $id = $this->input('flyer_template_id');

        if (! is_numeric($id)) {
            return null;
        }

        $template = FlyerTemplate::find((int) $id);

        if (! $template) {
            $validator->errors()->add('flyer_template_id', 'That design is not available to this masjid.');

            return null;
        }

        if (! $template->is_active) {
            $validator->errors()->add('flyer_template_id', 'That design has been switched off and cannot be used for new flyers.');

            return null;
        }

        return $this->resolvedTemplate = $template;
    }

    /**
     * Check the posted slot values against the design's declared slots.
     *
     * `required` is deliberately NOT enforced here. The Studio saves as the admin
     * types, so a half-filled flyer is a legitimate draft, not a validation failure —
     * FlyersController reports the still-empty required slots as `missing_slots`
     * instead, which is what the "ready to render" state is built from.
     */
    protected function validateContentAgainstTemplate($validator, FlyerTemplate $template, array $content): void
    {
        $slots = [];

        foreach ($template->slots() as $slot) {
            if (is_array($slot) && isset($slot['name'])) {
                $slots[$slot['name']] = $slot;
            }
        }

        // Unknown keys are rejected rather than quietly stored. Rendering substitutes
        // {{ slot }} into the template's HTML, so a key the design never declared is
        // dead weight at best, and at worst someone probing what the renderer echoes.
        foreach (array_keys($content) as $name) {
            if (! isset($slots[$name])) {
                $validator->errors()->add(
                    "content.{$name}",
                    "\"{$name}\" is not part of the {$template->name} design."
                );
            }
        }

        foreach ($slots as $name => $slot) {
            if (array_key_exists($name, $content)) {
                $this->validateSlotValue($validator, "content.{$name}", $slot, $content[$name]);
            }
        }
    }

    /** One slot's value: type, length, and (for `list`) the shape of each row. */
    private function validateSlotValue($validator, string $key, array $slot, mixed $value): void
    {
        $label = $slot['label'] ?? $slot['name'] ?? $key;

        // An empty slot is an unfinished draft, handled by missing_slots — not an error.
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        if (($slot['type'] ?? 'text') === 'list') {
            $this->validateListSlot($validator, $key, $slot, $label, $value);

            return;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            $validator->errors()->add($key, "{$label} must be text.");

            return;
        }

        $string = (string) $value;

        if (($slot['type'] ?? null) === 'image') {
            // Images are uploaded to the flyer, never inlined into the row: a base64
            // data URI here would put a multi-megabyte blob in a JSON column and be
            // re-sent on every list request.
            if (Str::startsWith(ltrim(Str::lower($string)), 'data:')) {
                $validator->errors()->add($key, "Upload the image for {$label} instead of embedding it in the flyer.");

                return;
            }

            if (Str::length($string) > 2048) {
                $validator->errors()->add($key, "The reference for {$label} is too long.");
            }

            return;
        }

        $max = $slot['maxLength'] ?? null;

        if (is_int($max) && $max > 0 && Str::length($string) > $max) {
            $validator->errors()->add(
                $key,
                "{$label} must be {$max} characters or fewer (it is currently " . Str::length($string) . ")."
            );
        }
    }

    /** A repeating slot (the bulletin's sessions): rows of the slot's own item_slots. */
    private function validateListSlot($validator, string $key, array $slot, string $label, mixed $value): void
    {
        if (! is_array($value) || array_is_list($value) === false) {
            $validator->errors()->add($key, "{$label} must be a list of rows.");

            return;
        }

        $maxItems = $slot['maxItems'] ?? null;

        if (is_int($maxItems) && count($value) > $maxItems) {
            $validator->errors()->add($key, "{$label} holds at most {$maxItems} rows.");
        }

        $itemSlots = [];

        foreach ($slot['item_slots'] ?? [] as $itemSlot) {
            if (is_array($itemSlot) && isset($itemSlot['name'])) {
                $itemSlots[$itemSlot['name']] = $itemSlot;
            }
        }

        foreach ($value as $index => $row) {
            if (! is_array($row)) {
                $validator->errors()->add("{$key}.{$index}", "Each row of {$label} must be a set of fields.");

                continue;
            }

            foreach (array_keys($row) as $field) {
                if (! isset($itemSlots[$field])) {
                    $validator->errors()->add(
                        "{$key}.{$index}.{$field}",
                        "\"{$field}\" is not a field of {$label}."
                    );
                }
            }

            foreach ($itemSlots as $field => $itemSlot) {
                if (array_key_exists($field, $row)) {
                    $this->validateSlotValue($validator, "{$key}.{$index}.{$field}", $itemSlot, $row[$field]);
                }
            }
        }
    }

    public function attributes(): array
    {
        return [
            'flyer_template_id' => 'design',
            'content' => 'flyer content',
        ];
    }
}
