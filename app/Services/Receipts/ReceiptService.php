<?php

namespace App\Services\Receipts;

use App\Models\Donation;
use App\Models\DonationReceipt;
use Illuminate\Support\Facades\DB;

/**
 * ReceiptService — issues the official tax receipt for a succeeded donation.
 *
 * Two invariants it guarantees:
 *
 *   1. Idempotent per donation — issuing twice (e.g. checkout.session.completed
 *      AND payment_intent.succeeded both fire, or a webhook is re-delivered)
 *      yields exactly ONE receipt. Enforced by an in-transaction existence
 *      check plus the DB unique on donation_id.
 *
 *   2. Gap-free serial per masjid — receipt serials are a per-masjid sequence
 *      1, 2, 3, … with no holes (regulators expect this). Allocated inside a
 *      DB transaction with `lockForUpdate()` on the masjid's existing receipts
 *      so two concurrent successes can't grab the same serial; the unique
 *      (masjid_id, serial_number) is the hard backstop.
 *
 * Runs in the UNBOUND webhook context, so masjid_id is set/filtered explicitly
 * (the BelongsToMasjid creating hook only stamps when a tenant is bound). All
 * amounts are integer minor units.
 */
class ReceiptService
{
    /**
     * Issue (or return the already-issued) receipt for a succeeded donation.
     * Returns null when the donation isn't eligible (not succeeded, or its fund
     * is flagged non-receiptable).
     */
    public function issueFor(Donation $donation): ?DonationReceipt
    {
        if (! $donation->isSucceeded()) {
            return null;
        }

        // Respect the fund's receiptable flag (e.g. a pass-through relief fund
        // may not issue tax receipts). Load without the tenant scope since we
        // run unbound.
        $fund = $donation->fund()->withoutGlobalScopes()->first();
        if ($fund && ! $fund->receiptable) {
            return null;
        }

        return DB::transaction(function () use ($donation) {
            // Idempotency: never mint a second receipt for the same donation.
            $existing = DonationReceipt::withoutMasjidScope()
                ->where('donation_id', $donation->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            // Gap-free per-masjid serial. Lock the masjid's receipt rows so a
            // concurrent success can't read the same max and collide.
            $lastSerial = DonationReceipt::withoutMasjidScope()
                ->where('masjid_id', $donation->masjid_id)
                ->lockForUpdate()
                ->max('serial_number');

            $nextSerial = ((int) $lastSerial) + 1;

            // A plain cash gift confers no advantage (nothing of value received
            // in return), so the full amount charged to the org is eligible.
            // gross = what the donor actually paid the charity (charged_amount,
            // which already includes any donor-covered fees). advantage stays 0
            // for this spike; the column exists for future benefit accounting
            // (e.g. event tickets), where eligible = gross − advantage.
            $gross = (int) $donation->charged_amount;
            $advantage = 0;
            $eligible = $gross - $advantage;

            $receipt = DonationReceipt::create([
                'masjid_id' => $donation->masjid_id,
                'donation_id' => $donation->id,
                'serial_number' => $nextSerial,
                'issue_date' => now()->toDateString(),
                'gross_amount' => $gross,
                'advantage_amount' => $advantage,
                'eligible_amount' => $eligible,
                'currency' => $donation->currency,
                'jurisdiction' => 'US',
                'status' => 'issued',
            ]);

            // Mirror the eligible amount onto the donation for quick reporting.
            $donation->forceFill(['receipt_eligible_amount' => $eligible])->save();

            return $receipt;
        });
    }
}
