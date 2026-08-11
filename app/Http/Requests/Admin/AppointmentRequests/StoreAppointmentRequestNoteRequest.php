<?php

namespace App\Http\Requests\Admin\AppointmentRequests;

use App\Http\Requests\BaseFormRequest;

/**
 * Add an internal staff note to an appointment request. The body is encrypted
 * at rest by the model cast; the author and tenant are server-derived in the
 * controller — neither user_id nor masjid_id is accepted from the client.
 *
 * Extends BaseFormRequest so a rejection leaves as the legacy {status:'failed'}
 * 422 instead of a raw ValidationException, which this app's JSON renderer
 * would turn into a 500.
 */
class StoreAppointmentRequestNoteRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'body' => 'required|string|max:10000',
        ];
    }
}
