<?php

namespace App\Support\Studio;

use App\Models\MasjidDomain;
use Illuminate\Support\Facades\Auth;

/**
 * Who is provisioning, handed to OrganisationProvisioner instead of read from
 * the guard inside it, and what the provisioner's optional steps did.
 *
 * The wizard's endpoint and Studio's draft path both provision through the one
 * body, and the draft path runs it from a job-shaped service where "the
 * authenticated user" is a question for its caller, not for the body. Passing it
 * in keeps the body free of ambient state.
 *
 * The actor is carried exactly as the guard reports it, not cast: it is written
 * to `masjids.created_by` and echoed back in the provision response, and the
 * extraction promised that response would not change by a byte.
 *
 * The outcome fields are filled only by Studio's optional steps (S8) and read
 * only by callers that asked for them: the wizard's response never reads them,
 * so it stays exactly what it was. They are here, rather than returned, because
 * create() returns the organisation and its signature is the one the demo fixture
 * and the wizard already call. Every one is written inside the caller's
 * transaction, so a caller must not act on them unless that transaction
 * committed.
 */
final class ProvisionContext
{
    /** @var array{changed: list<array{key: string, enabled: bool}>, unchanged: list<string>}|null null when no `capabilities` map was sent */
    public ?array $capabilitiesApplied = null;

    /** @var array<string, mixed>|null StarterSite::applyTo()'s report, null when no `layout_preset` was sent */
    public ?array $starterSite = null;

    /** @var list<MasjidDomain> the host rows written, managed first */
    public array $domains = [];

    public function __construct(
        public readonly int|string|null $actorId,
    ) {}

    /** The request's authenticated user, as the wizard's endpoint always used. */
    public static function fromAuth(): self
    {
        return new self(Auth::id());
    }
}
