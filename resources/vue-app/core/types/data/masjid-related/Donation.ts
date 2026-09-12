import { Fund } from "@/core/types/data/masjid-related/Fund";

// Read-only donation record. Mirrors App\Models\Donation. Donations are created
// and advanced ONLY by Stripe webhooks, so the admin UI never mutates them.
//
// IMPORTANT: every *_amount is an integer in MINOR UNITS (cents). Divide by 100
// (see formatCents) before display — never treat these as dollars directly.
export type DonationStatus = 'pending' | 'succeeded' | 'failed' | 'refunded';

/**
 * Which of the two things produced a gift's zakat designation. Mirrors
 * App\Support\ZakatDesignation::SOURCES.
 *
 *  - `donor`        the giver ticked the box at checkout — the strongest answer.
 *  - `fund_default` nobody said, and the gift went to a fund the org typed as
 *                   its zakat fund, so the fund's type stood in for an answer.
 *  - `admin`        a staff member recorded the designation on the giver's behalf
 *                   while entering a cash or cheque gift.
 *
 * Non-null ONLY when the gift is zakat: there is nothing to attribute about a
 * gift carrying no restriction.
 */
export type ZakatSource = 'donor' | 'fund_default' | 'admin';

export type DonationReceipt = {
    id: number;
    masjid_id: number;
    donation_id: number;
    serial_number: number;
    issue_date: string;
    gross_amount: number;
    advantage_amount: number;
    eligible_amount: number;
    currency: string;
    jurisdiction: string;
    status: 'issued' | 'void';
    created_at: string;
    updated_at: string;
};

export type Donation = {
    id: number;
    uuid: string;
    masjid_id: number;
    contact_id: number | null;
    fund_id: number;
    type: 'one_time' | 'recurring';
    intended_amount: number;
    charged_amount: number;
    currency: string;
    donor_covers_fees: boolean;
    /**
     * The restriction the GIVER placed on THIS gift — an accounting fact about
     * the money, not a label on the bucket it landed in.
     *
     * Read it; never re-derive it. `fund.type === 'zakat'` describes the org's
     * bucket and answers a different question: zakat is routinely given to a
     * general fund (most small masjids run one), and sadaqah toward a relief
     * appeal routinely lands in a zakat-typed one. Deriving the badge, the
     * filter or any figure from the fund would mis-state the restricted pot in
     * both directions — the whole reason .claude/rules/zakat.md exists.
     */
    is_zakat: boolean;
    zakat_source: ZakatSource | null;
    status: DonationStatus;
    stripe_payment_intent_id: string | null;
    stripe_checkout_session_id: string | null;
    stripe_charge_id: string | null;
    stripe_balance_transaction_id: string | null;
    application_fee_amount: number | null;
    stripe_fee_amount: number | null;
    net_amount: number | null;
    receipt_eligible_amount: number | null;
    created_at: string;
    updated_at: string;
    // Eager-loaded relations (present on show; fund present on index rows).
    fund?: Fund | null;
    receipt?: DonationReceipt | null;
};
