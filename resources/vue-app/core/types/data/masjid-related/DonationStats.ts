import { DonationStatus } from "@/core/types/data/masjid-related/Donation";

// Read-only giving numbers. Mirrors App\Support\DonationMetrics, which is the
// single implementation of the money rules behind both the stats endpoints and
// the Masjid Assistant.
//
// IMPORTANT: every *_cents field is an integer in MINOR UNITS. Format it at the
// view layer (formatCents) — never add, average or compare these as dollars.

export type DonationSource = 'stripe' | 'offline';

export const DONATION_SOURCES: DonationSource[] = ['stripe', 'offline'];

/** The three header buckets, in the order the dashboard reads them. */
export type DonationBucketKey = 'this_month' | 'year_to_date' | 'all_time';

export const DONATION_BUCKET_KEYS: DonationBucketKey[] = ['this_month', 'year_to_date', 'all_time'];

export type DonationBucket = {
    gross_cents: number;
    // Falls back to the gross for gifts with no settled net: offline gifts never
    // get one, and a Stripe gift has none until its balance transaction lands.
    net_cents: number;
    /** Distinct IDENTIFIED donors — gifts with no contact are excluded here but
     *  still counted in the money, which is what anonymous_gift_count explains. */
    donor_count: number;
    gift_count: number;
    average_gift_cents: number;
    anonymous_gift_count: number;
};

export type DonationSummary = Record<DonationBucketKey, DonationBucket>;

export type FundBreakdownRow = {
    fund_id: number;
    fund_name: string;
    is_active: boolean;
    gross_cents: number;
    net_cents: number;
    gift_count: number;
    donor_count: number;
    /** Calendar date (yyyy-mm-dd), not a timestamp — half these gifts only ever
     *  had a date. Null when the fund has taken nothing in the window. */
    last_gift_at: string | null;
};

/** What the numbers were computed under, so the page can label them rather than
 *  leaving the reader to assume a timezone and a currency. */
export type DonationStatsMeta = {
    timezone: string;
    currency: string;
    filters: {
        from: string | null;
        to: string | null;
        source: DonationSource | null;
        status: DonationStatus | null;
    };
};

/**
 * The ledger's full filter vocabulary — shared verbatim by the donations list
 * and the CSV export, which is why they can never disagree about what is shown.
 *
 * Blank string means "unset"; the query builders omit those rather than sending
 * an empty value, which the server's `in:` rules would reject.
 */
export type DonationLedgerFilters = {
    /** Inclusive bounds on the canonical gift date, COALESCE(donated_at, created_at). */
    from: string;
    to: string;
    fund_id: number | '';
    source: DonationSource | '';
    status: DonationStatus | '';
    search: string;
};

/**
 * What the stats endpoints actually accept.
 *
 * Narrower than the ledger's on purpose: the summary is a whole-masjid figure and
 * the breakdown reports every fund on its own row, so neither takes a fund_id (nor
 * a donor search). Sending one would be silently dropped server-side — worse than
 * not sending it, because the header would look filtered and not be.
 */
export type DonationStatsFilters = Pick<DonationLedgerFilters, 'from' | 'to' | 'source' | 'status'>;
