<?php

namespace App\Http\Requests\Admin\MealMenus;

use App\Http\Requests\BaseFormRequest;
use App\Models\MealOrder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST .../orders/{order_id}/mark-paid. How the money came (`paid_via`), always
 * one of MealOrder::PAID_VIA: the office reads it to know how each order was
 * paid, and a default would be a guess written into a money record.
 *
 * A refusal answers with one sentence in `data`, the shape of the board's other
 * refusals, rather than the usual per-field errors. A board still holding the
 * bundle from before this question existed posts no method at all and shows
 * `data` only when it is a string, so this is the one place it can be told to
 * reload.
 */
class MarkMealOrderPaidRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'paid_via' => ['required', 'string', Rule::in(MealOrder::PAID_VIA)],
        ];
    }

    /** "Choose how they paid: Cash, Zelle, Masjid Terminal or Stripe. …", from the model's own labels. */
    public static function refusal(): string
    {
        $labels = array_values(MealOrder::PAID_VIA_LABELS);
        $last = array_pop($labels);

        return 'Choose how they paid: ' . implode(', ', $labels) . " or {$last}. If the board does not ask, reload the page.";
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 'failed',
            'data' => self::refusal(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
