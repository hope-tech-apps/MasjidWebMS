<?php

namespace App\Http\Requests\Admin\Donations;

use App\Http\Requests\BaseFormRequest;

/**
 * Correct a manually-recorded gift.
 *
 * Every rule is `sometimes`: this is a PATCH in spirit even when sent as a PUT,
 * so an edit that only fixes the donor cannot accidentally blank the note by
 * omitting it. A key that is absent means "leave it alone"; a key that is
 * present and null means "clear it" (that is how a gift is put back to general).
 *
 * WHAT IS NOT HERE, and why. There is no `status`, no `intended_amount`, no
 * Stripe identifier and no `source`. A gift's money-settlement facts are either
 * Stripe's (and owned by the webhook) or, for an offline gift, the amount — which
 * IS editable here as `amount`, because a mis-keyed cheque is the ordinary reason
 * to open this form. What stays out is anything that would let an offline row
 * impersonate a Stripe one. The controller refuses the whole request for a gift
 * whose source is not 'offline'.
 */
class UpdateOfflineDonationRequest extends BaseFormRequest
{
    /** The payment methods the picker offers — the `in:` allow-list, in one place. */
    private const METHODS = ['cash', 'check', 'zelle', 'venmo', 'paypal', 'square', 'credit', 'giftcard', 'other'];

    /**
     * Heal a legacy or blank payment method before validation.
     *
     * A gift imported or recorded before the method picker existed can carry a
     * value the picker no longer offers — 'unknown' is the common one. The picker
     * then shows BLANK (it has no such option) and, on save, submits that stale
     * value (or, once ConvertEmptyStringsToNull has run, a bare null). Either one
     * fails the `in:` rule with "The selected payment method is invalid", and
     * because every OTHER field here is `sometimes`, that single bad method blocks
     * an edit of a completely unrelated field — a fund correction, a fixed date —
     * with an error the form does not surface. Measured on a real Burlington gift.
     *
     * So: if a method is PRESENT but not one the picker offers, treat it as "the
     * hand-recorded method was never captured" and normalise it to 'other' — a
     * real, selectable value that means exactly that. A method that is ABSENT is
     * left absent (the `sometimes` rule keeps the stored value); a VALID selection
     * passes through untouched. The one thing that can no longer happen is a
     * legacy value making the whole gift uneditable.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('payment_method')
            && ! in_array($this->input('payment_method'), self::METHODS, true)) {
            $this->merge(['payment_method' => 'other']);
        }
    }

    public function rules(): array
    {
        return [
            'fund_id' => ['sometimes', 'integer'],
            // Two ways to name the donor, same as recording one: an existing
            // contact by id, or a name that is found-or-created. Present-and-null
            // clears the donor and books the gift as general.
            'contact_id' => ['sometimes', 'nullable', 'integer'],
            'donor_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'amount' => ['sometimes', 'numeric', 'min:0.01', 'max:1000000'],
            'payment_method' => ['sometimes', 'in:'.implode(',', self::METHODS)],
            'check_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'donated_at' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'zakat' => ['sometimes', 'boolean'],
        ];
    }
}
