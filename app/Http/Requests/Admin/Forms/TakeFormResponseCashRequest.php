<?php

namespace App\Http\Requests\Admin\Forms;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST .../responses/{response_id}/take-cash.
 *
 * `confirm_holder_checked` (optional, accepted: true, "true", "1", "yes", "on"): the admin
 * has checked, in the Stripe dashboard of the organisation a card page was opened through
 * (DECISIONS.md 2026-09-15), that the page was not paid. It is read only when Stripe no
 * longer lets the platform check that page itself AND the page's pinned expiry has passed
 * (FormResponsesController::settleByHand()). Everywhere else it changes nothing, so every
 * existing caller, which sends no body, is answered exactly as before.
 */
class TakeFormResponseCashRequest extends BaseFormRequest
{
    public const CONFIRM_HOLDER_CHECKED = 'confirm_holder_checked';

    public function rules(): array
    {
        return [
            self::CONFIRM_HOLDER_CHECKED => ['sometimes', 'accepted'],
        ];
    }

    /** Whether the admin said they checked the holder's Stripe dashboard. */
    public function confirmsHolderChecked(): bool
    {
        return self::confirmed($this);
    }

    /** Shared with MarkFormResponsePaidRequest: a form-encoded "true" or "1" counts. */
    public static function confirmed(BaseFormRequest $request): bool
    {
        return $request->has(self::CONFIRM_HOLDER_CHECKED) && $request->boolean(self::CONFIRM_HOLDER_CHECKED);
    }

    /** One sentence under `message`, like this screen's other refusals. */
    public static function refusal(): string
    {
        return 'Tick "I have checked" only once you have checked the account holder\'s Stripe dashboard. If the screen does not ask, reload the page.';
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'failed',
            'message' => self::refusal(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
