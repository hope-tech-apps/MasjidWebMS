<?php

namespace App\Support;

/**
 * One person to nudge, already resolved to a real address.
 *
 * The address is the recipient's chosen SIGN-IN address (a guardian's
 * login_email, a teacher's users.email), not a CRM contact field — the nudge
 * says "sign in to read it", so it must land where they sign in. The job never
 * re-derives an address; it only ever reads what the resolver put here.
 */
final class NudgeRecipient
{
    public function __construct(
        public string $address,
        public ?string $name,
        /** 'family' (a guardian) or 'staff' (a teacher) — which realm they sign into. */
        public string $realm,
    ) {
    }
}
