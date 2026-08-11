<?php

namespace App\Support;

use App\Models\MasjidUser;

/**
 * The answer App\Support\TenantResolver gives about one request.
 *
 * It is a three-state answer, and collapsing it to two is how cross-tenant
 * reads happen. "Bind this membership" and "deny" are obvious; the third —
 * "leave the context UNBOUND" — looks like a non-answer but is a real, narrow
 * verdict meaning *this route is not about one masjid*. In a system with no
 * row-level security an unbound context adds NO filter
 * (App\Models\Concerns\BelongsToMasjid), so a resolver that returned null for
 * both "no answer" and "not applicable" would turn every refusal into an
 * unfiltered read of every organisation's data.
 *
 * Hence three named constructors and no nullable return type: a caller cannot
 * accidentally treat a denial as an absence.
 */
final class TenantResolution
{
    private function __construct(
        private readonly ?MasjidUser $membership,
        private readonly bool $denied,
        private readonly string $reason,
    ) {
    }

    /** Bind the tenant to this VERIFIED membership. */
    public static function bind(MasjidUser $membership, string $reason): self
    {
        return new self($membership, false, $reason);
    }

    /**
     * Leave the context unbound — the route genuinely is not about one masjid.
     * Callers must be able to say WHY in one line; there is no default reason,
     * because "I could not work it out" is a denial, not an exemption.
     */
    public static function unbound(string $reason): self
    {
        return new self(null, false, $reason);
    }

    /** Refuse the request outright (403). The fail-closed verdict. */
    public static function denied(string $reason): self
    {
        return new self(null, true, $reason);
    }

    public function isDenied(): bool
    {
        return $this->denied;
    }

    /** The membership to bind, or null when the verdict is unbound/denied. */
    public function membership(): ?MasjidUser
    {
        return $this->membership;
    }

    /**
     * Why the resolver decided this. Diagnostic only — it must NEVER reach the
     * response body: the 403 message is a fixed string that predates this slice
     * and the admin SPA switches on it (see ResolveMasjidTenant). Telling a
     * caller *which* of the fail-closed branches refused them is also free
     * reconnaissance about other organisations' ids.
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
