<?php

namespace App\Support\Studio;

use Illuminate\Support\Facades\Auth;

/**
 * Who is provisioning, handed to OrganisationProvisioner instead of read from
 * the guard inside it.
 *
 * The wizard's endpoint and Studio's draft path both provision through the one
 * body, and the draft path runs it from a job-shaped service where "the
 * authenticated user" is a question for its caller, not for the body. Passing it
 * in keeps the body free of ambient state.
 *
 * The actor is carried exactly as the guard reports it, not cast: it is written
 * to `masjids.created_by` and echoed back in the provision response, and the
 * extraction promised that response would not change by a byte.
 */
final class ProvisionContext
{
    public function __construct(
        public readonly int|string|null $actorId,
    ) {}

    /** The request's authenticated user, as the wizard's endpoint always used. */
    public static function fromAuth(): self
    {
        return new self(Auth::id());
    }
}
