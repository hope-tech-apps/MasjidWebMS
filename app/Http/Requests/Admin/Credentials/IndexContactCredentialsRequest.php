<?php

namespace App\Http\Requests\Admin\Credentials;

use App\Http\Requests\BaseFormRequest;

/**
 * Query-string boundary for the credential list.
 *
 * ?expiring_within_days=N narrows the list to credentials whose expiry falls
 * within the next N days (today inclusive, already-expired excluded) — the
 * renewal chase list a clinic coordinator actually works from.
 */
class IndexContactCredentialsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // min:1 — "expiring within 0 days" is not a window, and a negative
            // number would silently return nothing. Ten years is a generous cap
            // that still rejects garbage.
            'expiring_within_days' => 'sometimes|integer|min:1|max:3650',
        ];
    }

    /** The validated window, or null when the caller asked for everything. */
    public function expiringWithinDays(): ?int
    {
        return $this->filled('expiring_within_days')
            ? (int) $this->input('expiring_within_days')
            : null;
    }
}
