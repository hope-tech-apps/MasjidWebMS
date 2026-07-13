// A donation fund (designation): Zakat, Sadaqah, Fitra, Waqf, General.
// Mirrors App\Models\Fund. Amounts/donations hang off it server-side.
export type FundType = 'zakat' | 'sadaqah' | 'fitra' | 'waqf' | 'general';

export const FUND_TYPES: FundType[] = ['zakat', 'sadaqah', 'fitra', 'waqf', 'general'];

export type Fund = {
    id: number;
    masjid_id: number;
    name: string;
    type: FundType;
    receiptable: boolean;
    is_active: boolean;
    created_at: string;
    updated_at: string;
};

// Shape submitted by the create/edit form. The server stamps masjid_id +
// timestamps; the tenant guardrail owns masjid_id, so it is never sent.
export type FundPayload = {
    name: string;
    type: FundType;
    receiptable: boolean;
    is_active: boolean;
};
