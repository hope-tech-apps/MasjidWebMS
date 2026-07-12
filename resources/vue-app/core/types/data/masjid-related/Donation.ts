import { Fund } from "@/core/types/data/masjid-related/Fund";

// Read-only donation record. Mirrors App\Models\Donation. Donations are created
// and advanced ONLY by Stripe webhooks, so the admin UI never mutates them.
//
// IMPORTANT: every *_amount is an integer in MINOR UNITS (cents). Divide by 100
// (see formatCents) before display — never treat these as dollars directly.
export type DonationStatus = 'pending' | 'succeeded' | 'failed' | 'refunded';

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
