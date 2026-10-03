<?php

namespace App\Http\Requests\Admin\TvDisplay;

use App\Http\Requests\BaseFormRequest;
use App\Support\TvBoard;

/**
 * What an organisation may choose for its lobby TV board (the "TV Display"
 * page). Six settings and nothing else; see App\Support\TvBoard.
 *
 * Every field is `nullable`: null means "not chosen", and the board then gets
 * what it got before. A field the request does not carry is LEFT AS IT IS (the
 * controller reads only what was validated), so a partial save cannot clear a
 * setting by omission.
 *
 * BOOLEANS. A form-encoded client sends "true" / "false", which Laravel's
 * `boolean` rule refuses (.claude/rules/shipping.md), so they are coerced
 * here. Nonsense is NOT coerced: it is left for the rule to refuse. `false` on
 * `is_enabled` blanks a live lobby screen down to the paused board, so a value
 * that cannot be read must be a 422 and never a guess.
 *
 * TEXT. The public tv-config endpoint echoes both strings to anyone who asks,
 * and the board draws them at a fixed size with no truncation: a long title
 * shrinks the slides and a long caption shrinks the prayer times. Hence the
 * limits, and one line each (no line breaks or other control characters).
 *
 * `masjid_id` is not accepted: the organisation is the bound tenant.
 * Extends BaseFormRequest so a refusal is the {status:'failed', data} 422 the
 * admin screens read.
 */
class SaveTvDisplaySettingsRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $coerced = [];

        foreach (TvBoard::SWITCHES as $key) {
            if (! $this->has($key) || $this->input($key) === null) {
                continue;
            }

            $value = filter_var($this->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($value !== null) {
                $coerced[$key] = $value;
            }
        }

        $this->merge($coerced);
    }

    public function rules(): array
    {
        $oneLine = 'not_regex:/[\x00-\x1F\x7F]/u';

        return [
            'is_enabled' => ['nullable', 'boolean'],
            'show_prayer_panel' => ['nullable', 'boolean'],
            'show_qr' => ['nullable', 'boolean'],
            'header_title' => ['nullable', 'string', 'max:' . TvBoard::HEADER_TITLE_MAX, $oneLine],
            'donate_caption' => ['nullable', 'string', 'max:' . TvBoard::DONATE_CAPTION_MAX, $oneLine],
            'carousel_interval_seconds' => [
                'nullable',
                'integer',
                'min:' . TvBoard::CAROUSEL_INTERVAL_MIN,
                'max:' . TvBoard::CAROUSEL_INTERVAL_MAX,
            ],
        ];
    }

    public function messages(): array
    {
        $onOff = 'Choose on or off.';
        $oneLine = 'Keep this to one line.';
        $seconds = 'Seconds per slide is a whole number from ' . TvBoard::CAROUSEL_INTERVAL_MIN
            . ' to ' . TvBoard::CAROUSEL_INTERVAL_MAX . '.';

        return [
            'is_enabled.boolean' => $onOff,
            'show_prayer_panel.boolean' => $onOff,
            'show_qr.boolean' => $onOff,
            'header_title.max' => 'The title can be at most ' . TvBoard::HEADER_TITLE_MAX . ' characters.',
            'header_title.not_regex' => $oneLine,
            'header_title.string' => $oneLine,
            'donate_caption.max' => 'The words under the code can be at most ' . TvBoard::DONATE_CAPTION_MAX . ' characters.',
            'donate_caption.not_regex' => $oneLine,
            'donate_caption.string' => $oneLine,
            'carousel_interval_seconds.integer' => $seconds,
            'carousel_interval_seconds.min' => $seconds,
            'carousel_interval_seconds.max' => $seconds,
        ];
    }
}
