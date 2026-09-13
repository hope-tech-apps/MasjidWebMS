<?php

namespace App\Http\Requests\Admin\Forms;

use App\Http\Requests\BaseFormRequest;
use App\Models\FormResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST .../responses/{response_id}/mark-paid-external. `via`: how the money came, one of
 * FormResponse::PAID_VIA_EXTERNAL (Zelle, Cash App, Venmo, check; BISS, 2026-09-13).
 *
 * Optional here, because a Wix payer on the festival form has always been marked paid
 * without one. REQUIRED for a family that chose to pay the office, and that is decided
 * on the locked row (FormResponsesController::settleByHand()), since only the row knows.
 * Cash is not on the list: "Take cash" records cash and says so itself.
 *
 * A refusal answers in the shape of this screen's other refusals, one sentence under
 * `message`, so a screen on an old bundle shows it as it shows "Not paid yet.".
 */
class MarkFormResponsePaidRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'via' => ['nullable', 'string', Rule::in(FormResponse::PAID_VIA_EXTERNAL)],
        ];
    }

    /** "Choose how they paid: Zelle, Cash App, Venmo or Check. …", from the model's own labels. */
    public static function refusal(): string
    {
        $labels = array_map(fn (string $via) => FormResponse::PAID_VIA_LABELS[$via], FormResponse::PAID_VIA_EXTERNAL);
        $last = array_pop($labels);

        return 'Choose how they paid: ' . implode(', ', $labels) . " or {$last}. Cash is recorded with Take cash. If the screen does not ask, reload the page.";
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'failed',
            'message' => self::refusal(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
