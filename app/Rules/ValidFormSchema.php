<?php

namespace App\Rules;

use App\Support\FormSchema;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Structural validation for a form's `schema`.
 *
 * This runs when an admin SAVES a form, and it is what lets the renderer and the
 * submission validator stay simple: by the time a schema is stored, every field has a
 * name and a known type, every choice field has options, names are unique within their
 * scope, and any conditional rule points at a section and field that actually exist.
 *
 * Rejecting a bad schema here is much better than discovering it when a congregant is
 * halfway through a camp registration.
 */
class ValidFormSchema implements ValidationRule
{
    /** Field name must be a safe identifier — it becomes an array key and an input id. */
    private const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The form layout must be an object.');

            return;
        }

        $sections = $value['sections'] ?? null;

        if (! is_array($sections) || $sections === []) {
            $fail('A form needs at least one section.');

            return;
        }

        $sectionIds = [];
        $repeatableCount = 0;
        $allFieldNames = [];

        foreach ($sections as $index => $section) {
            $label = 'Section ' . ($index + 1);

            if (! is_array($section)) {
                $fail("{$label} is malformed.");

                return;
            }

            $sectionId = $section['id'] ?? null;

            if (! is_string($sectionId) || ! preg_match(self::NAME_PATTERN, $sectionId)) {
                $fail("{$label} needs an id made of letters, numbers and underscores, starting with a letter.");

                return;
            }

            if (in_array($sectionId, $sectionIds, true)) {
                $fail("Two sections share the id \"{$sectionId}\". Section ids must be unique.");

                return;
            }
            $sectionIds[] = $sectionId;

            if (! empty($section['repeatable'])) {
                $repeatableCount++;

                $min = $section['minEntries'] ?? 0;
                $max = $section['maxEntries'] ?? 0;

                if (! is_numeric($min) || $min < 0) {
                    $fail("{$label}: the minimum number of entries must be zero or more.");

                    return;
                }

                if ($max !== 0 && $max !== null) {
                    if (! is_numeric($max) || $max < 1) {
                        $fail("{$label}: the maximum number of entries must be at least one.");

                        return;
                    }

                    if ((int) $max < (int) $min) {
                        $fail("{$label}: the maximum number of entries cannot be lower than the minimum.");

                        return;
                    }
                }
            }

            $fields = $section['fields'] ?? null;

            if (! is_array($fields) || $fields === []) {
                $fail("{$label} needs at least one question.");

                return;
            }

            $namesInSection = [];

            foreach ($fields as $field) {
                if (! is_array($field)) {
                    $fail("{$label} contains a malformed question.");

                    return;
                }

                $name = $field['name'] ?? null;

                if (! is_string($name) || ! preg_match(self::NAME_PATTERN, $name)) {
                    $fail("{$label}: every question needs a name made of letters, numbers and underscores, starting with a letter.");

                    return;
                }

                if (in_array($name, $namesInSection, true)) {
                    $fail("{$label}: two questions share the name \"{$name}\".");

                    return;
                }
                $namesInSection[] = $name;

                // Flat field names share one namespace with section ids, because both
                // become top-level keys of the submitted payload.
                if (empty($section['repeatable'])) {
                    if (in_array($name, $allFieldNames, true)) {
                        $fail("The question name \"{$name}\" is used more than once.");

                        return;
                    }
                    if (in_array($name, $sectionIds, true) && $name !== $sectionId) {
                        $fail("The question name \"{$name}\" collides with a section id.");

                        return;
                    }
                    $allFieldNames[] = $name;
                }

                $type = $field['type'] ?? null;

                if (! is_string($type) || ! in_array($type, FormSchema::FIELD_TYPES, true)) {
                    $fail("{$label}: \"{$name}\" has an unsupported question type.");

                    return;
                }

                if (! isset($field['label']) || ! is_string($field['label']) || trim($field['label']) === '') {
                    $fail("{$label}: \"{$name}\" needs a label.");

                    return;
                }

                if (in_array($type, ['select', 'radio', 'checkboxGroup'], true)) {
                    $options = $field['options'] ?? null;

                    if (! is_array($options) || $options === []) {
                        $fail("{$label}: \"{$name}\" is a choice question, so it needs at least one option.");

                        return;
                    }

                    $optionValues = [];

                    foreach ($options as $option) {
                        $optValue = is_array($option) ? ($option['value'] ?? null) : null;
                        $optLabel = is_array($option) ? ($option['label'] ?? null) : null;

                        if (! is_string($optValue) || $optValue === '' || ! is_string($optLabel) || $optLabel === '') {
                            $fail("{$label}: every option on \"{$name}\" needs a value and a label.");

                            return;
                        }

                        if (in_array($optValue, $optionValues, true)) {
                            $fail("{$label}: \"{$name}\" has two options with the value \"{$optValue}\".");

                            return;
                        }
                        $optionValues[] = $optValue;
                    }
                }

                if ($type === 'number' && isset($field['min'], $field['max'])) {
                    if (is_numeric($field['min']) && is_numeric($field['max']) && $field['max'] < $field['min']) {
                        $fail("{$label}: \"{$name}\" has a maximum lower than its minimum.");

                        return;
                    }
                }

                if (isset($field['requiredIf'])) {
                    if ($error = $this->checkConditional($field['requiredIf'], $name, $sections)) {
                        $fail($error);

                        return;
                    }
                }
            }
        }

        // More than one repeatable section would make entry counting, capacity and the
        // fee total ambiguous — the renderer and FormSchema both assume at most one.
        if ($repeatableCount > 1) {
            $fail('A form can have at most one repeatable section.');
        }
    }

    /**
     * A conditional must point at a repeatable section and a numeric field inside it,
     * or it can never fire.
     *
     * @param  array<int,mixed>  $sections
     */
    private function checkConditional(mixed $rule, string $fieldName, array $sections): ?string
    {
        if (! is_array($rule)) {
            return "\"{$fieldName}\" has a malformed conditional rule.";
        }

        if (($rule['rule'] ?? null) !== 'anyEntryUnder') {
            return "\"{$fieldName}\" uses an unsupported conditional rule.";
        }

        $targetSection = $rule['section'] ?? null;
        $targetField = $rule['field'] ?? null;

        if (! is_numeric($rule['value'] ?? null)) {
            return "The conditional rule on \"{$fieldName}\" needs a numeric threshold.";
        }

        foreach ($sections as $section) {
            if (! is_array($section) || ($section['id'] ?? null) !== $targetSection) {
                continue;
            }

            if (empty($section['repeatable'])) {
                return "The conditional rule on \"{$fieldName}\" points at \"{$targetSection}\", which is not a repeatable section.";
            }

            foreach ($section['fields'] ?? [] as $candidate) {
                if (is_array($candidate) && ($candidate['name'] ?? null) === $targetField) {
                    if (($candidate['type'] ?? null) !== 'number') {
                        return "The conditional rule on \"{$fieldName}\" compares \"{$targetField}\", which is not a number question.";
                    }

                    return null;
                }
            }

            return "The conditional rule on \"{$fieldName}\" points at a question \"{$targetField}\" that does not exist in \"{$targetSection}\".";
        }

        return "The conditional rule on \"{$fieldName}\" points at a section \"{$targetSection}\" that does not exist.";
    }
}
