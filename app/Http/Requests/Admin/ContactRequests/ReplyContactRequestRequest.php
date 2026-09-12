<?php

namespace App\Http\Requests\Admin\ContactRequests;

use App\Http\Requests\BaseFormRequest;

class ReplyContactRequestRequest extends BaseFormRequest
{
    /**
     * `idempotency_key` is what stops one reply reaching a member of the public
     * twice (PLAN T-042d).
     *
     * OPTIONAL, and no longer the guard's only source. A key minted by the
     * client is only ever as good as the client's memory of it, and the SPA's
     * was minted per modal-open in component state: reopening the modal, or
     * having the same message open in a second tab, produced a different key
     * and the guard did nothing. So the SPA now sends none, and
     * ContactRequestsController DERIVES a key from the message being answered
     * and the exact text being sent — two tabs answering message 14 with the
     * same words arrive carrying the same derived key whether or not either of
     * them remembers anything. A caller that DOES supply a key keeps control of
     * its own replays and that key wins; nothing that predates this rule breaks,
     * and no caller is left unguarded for having sent nothing.
     *
     * The unique index on (contact_us_message_id, idempotency_key) is what
     * enforces one ROW per key; the `sending_at` claim taken before the mailer
     * is called is what enforces one SEND. This rule only bounds the shape.
     *
     * Bounded at 64 to match the column, and restricted to characters that can
     * appear in a UUID or a random token so the value cannot smuggle anything
     * into a log line or a `LIKE`.
     */
    public function rules(): array
    {
        return [
            'reply' => 'required|string|min:1|max:5000',
            'idempotency_key' => 'nullable|string|max:64|regex:/^[A-Za-z0-9._-]+$/',
        ];
    }

    /**
     * Normalise the reply BEFORE it is validated, because the normalised string
     * is the one that gets emailed, stored, and hashed into the derived
     * idempotency key.
     *
     * Two reasons this cannot be left to the controller:
     *
     *  - `min:1` has to apply to the text that will actually be sent. A reply of
     *    nothing but spaces and newlines passes `min:1` unnormalised, and the
     *    person who wrote in then receives an empty email from the masjid.
     *  - The derived key is a hash of the body. If one caller sends a trailing
     *    newline and the retry does not, the two hash differently, the guard
     *    misses, and the same answer goes out twice. Normalising at the edge
     *    means every caller of this endpoint is hashing the same bytes.
     *
     * CRLF and CR collapse to LF (a browser textarea submits CRLF, an API client
     * usually does not) and the ends are trimmed. Nothing inside the text is
     * touched: the admin was shown this body in the confirmation step and it has
     * to go out as they read it.
     */
    protected function prepareForValidation(): void
    {
        $reply = $this->input('reply');

        if (is_string($reply)) {
            $this->merge([
                'reply' => trim(str_replace(["\r\n", "\r"], "\n", $reply)),
            ]);
        }
    }
}
