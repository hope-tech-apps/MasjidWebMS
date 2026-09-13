<?php

namespace App\Services\Stripe;

use App\Models\Masjid;

/**
 * Where a form card payment of one organisation lands right now
 * (FormChargeAccount::for(); DECISIONS.md 2026-09-15).
 *
 *   organisation  the organisation the registration belongs to (its emails, its
 *                 branding, its screens)
 *   holder        the organisation whose Connect account is charged: the organisation
 *                 itself, or its parent when a SuperAdmin linked the two
 *   accountId     the holder's acct_ string, read live
 *   linked        true when the holder is another organisation
 *
 * Never serialised to a response: the child organisation is never told the holder's
 * account id.
 */
final readonly class FormCharge
{
    public function __construct(
        public Masjid $organisation,
        public Masjid $holder,
        public string $accountId,
        public bool $linked,
    ) {
    }
}
