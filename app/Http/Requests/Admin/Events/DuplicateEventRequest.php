<?php

namespace App\Http\Requests\Admin\Events;

use App\Http\Requests\BaseFormRequest;

/**
 * The request boundary for "Duplicate this event" (PLAN T-042a).
 *
 * T-042a asked for repeating events. What ships is a fan-out to dates a human
 * typed, NOT a recurrence rule, and this class is where that decision becomes
 * enforceable: the body carries an explicit list of dates, so what gets created
 * is exactly what the admin reviewed in the modal — there is no rule to
 * re-evaluate later, no generation horizon, and no timezone question that
 * `events.start` (a naive `Y-m-d H:i` datetime with no tz column anywhere on
 * the model) cannot answer. See EventsController::duplicate() for the full
 * reasoning; the short version is that a recurrence TABLE would have to carry
 * `masjid_id` while `Event` itself is still hand-scoped legacy, splitting one
 * feature across two tenancy regimes.
 *
 * WHY EACH RULE, and what breaks without it:
 *
 * - `dates` is `required|array|min:1` — an empty batch must be a 422, not a
 *   silent 200 that created nothing. "It said success and nothing happened" is
 *   the single most expensive failure shape in this codebase.
 *
 * - `max:12` (self::MAX_DATES) caps the fan-out. Every copy is a real row that
 *   the mobile app, the website `events` section and the assistant all read,
 *   and the whole batch runs inside one DB transaction. Twelve is a term of
 *   weekly classes — enough for the thing admins actually retype, small enough
 *   that a fat-fingered paste cannot open a 500-row transaction on prod. The
 *   Vue modal caps its rows at the same constant; the server keeps its own
 *   copy because the client's cap is advice, not a boundary.
 *
 * - `dates.*.start` is `date_format:Y-m-d H:i`, the SAME format StoreEventRequest
 *   pins. `date` (the looser rule) would accept "next tuesday" and hand the
 *   column a value the four independent readers of this table each parse
 *   differently.
 *
 * - `distinct` on `dates.*.start` is the guard against the commonest way this
 *   screen goes wrong: the admin adds a row, hesitates, adds it again. Without
 *   it the batch creates two identical events on one date and nothing tells
 *   anybody. (The controller separately refuses a date that ALREADY holds an
 *   identically-titled event, which is the double-click version of the same
 *   mistake.)
 *
 * - `dates.*.end` uses `after:dates.*.start` — Laravel replaces the asterisk
 *   with the row's own index, so row 3's end is compared with row 3's start,
 *   not row 0's. Written any other way this rule silently compares the wrong
 *   pair and lets an end-before-start row through.
 *
 * WHAT IS DELIBERATELY ABSENT: no `after:today`. StoreEventRequest does not
 * refuse a past date either, and duplicating onto a past date is a legitimate
 * record-keeping act. The Vue modal refuses past dates client-side as guidance
 * (mirroring EventFormView's yup `.min(TODAY)`); the server rule stays
 * independent of that so tightening one never silently loosens the other.
 */
class DuplicateEventRequest extends BaseFormRequest
{
    /**
     * How many copies one duplicate call may create. Mirrored by the modal's
     * "Add another date" cap — kept as a constant so the test asserts the
     * boundary rather than a magic number that drifts away from the UI.
     */
    public const MAX_DATES = 12;

    public function rules(): array
    {
        return [
            'dates' => 'required|array|min:1|max:' . self::MAX_DATES,
            'dates.*' => 'required|array',
            'dates.*.start' => 'required|date_format:Y-m-d H:i|distinct',
            'dates.*.end' => 'nullable|date_format:Y-m-d H:i|after:dates.*.start',
        ];
    }

    public function messages(): array
    {
        return [
            'dates.required' => 'Choose at least one date to copy this event onto.',
            'dates.max' => 'You can copy an event onto at most ' . self::MAX_DATES . ' dates at a time.',
            'dates.*.start.required' => 'Each copy needs a start date and time.',
            'dates.*.start.date_format' => 'Each start must be a date and time (YYYY-MM-DD HH:MM).',
            'dates.*.start.distinct' => 'The same date is listed twice — remove the duplicate row.',
            'dates.*.end.date_format' => 'Each end must be a date and time (YYYY-MM-DD HH:MM).',
            'dates.*.end.after' => 'A copy cannot end before it starts.',
        ];
    }
}
