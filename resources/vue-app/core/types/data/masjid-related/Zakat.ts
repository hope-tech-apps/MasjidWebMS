// The zakat calculator's public payloads, and the organization's nisab price
// setting behind them (T-043c). Mirrors App\Support\ZakatCalculator and
// App\Models\MasjidZakatSetting.
//
// Money is INTEGER MINOR UNITS (cents) everywhere below, exactly as the server
// sends and expects it. Nothing here holds a decimal: the figures are summed and
// divided by 40 on the server, and a float on this side would put a rounding
// error inside a religious obligation.

export type NisabBasis = 'gold' | 'silver';

export const NISAB_BASES: NisabBasis[] = ['gold', 'silver'];

// Which layer supplied the metal price behind the threshold. Precedence is
// request > organization > config; `null` means no price existed anywhere, and
// there is therefore no threshold.
export type NisabPriceSource = 'request' | 'organization' | 'config';

// How old the price is KNOWN to be. `stale` and `undated` are not softer
// `current`s: on a stale price the server withholds its verdict entirely, and on
// an undated one nothing can be said about the price's age at all.
export type NisabFreshness = 'current' | 'stale' | 'undated';

export type Nisab = {
    basis: NisabBasis;
    grams: number;
    price_per_gram_minor: number | null;
    price_source: NisabPriceSource | null;
    // Only an organization's own quote carries a date and a citation.
    price_quoted_on: string | null;
    price_quoted_from: string | null;
    price_freshness: NisabFreshness | null;
    price_freshness_days: number;
    threshold_minor: number | null;
    // THREE states, and they are not interchangeable:
    //   true  — the declared wealth reaches the threshold
    //   false — it does not, so nothing is due
    //   null  — we cannot tell, because the price is missing or out of date.
    // Rendering null as "no zakat due" is the bug this whole feature exists to
    // prevent.
    meets_nisab: boolean | null;
};

export type ZakatAssumption = {
    key: string;
    statement: string;
};

// GET /api/v1/zakat/nisab — the threshold on its own, no wealth declared.
export type NisabReference = {
    currency: string;
    rate: { fraction: string; percent: number };
    nisab: Nisab;
    assumptions: ZakatAssumption[];
};

// POST /api/v1/zakat/calculate
export type ZakatCalculation = {
    currency: string;
    rate: { fraction: string; percent: number };
    assets: { items: Record<string, number>; total_minor: number };
    liabilities: { items: Record<string, number>; total_minor: number };
    net_zakatable_wealth_minor: number;
    nisab: Nisab;
    // Always present: one fortieth of net wealth, whether or not the threshold
    // could be evaluated. It is the arithmetic, not the answer.
    zakat_at_rate_minor: number;
    // null when the threshold is unknown or out of date — never 0, which would
    // read as "you owe nothing".
    zakat_due_minor: number | null;
    assumptions: ZakatAssumption[];
    disclaimer: string;
};

// The stored per-organization price. Mirrors App\Models\MasjidZakatSetting.
export type ZakatSetting = {
    id: number;
    masjid_id: number;
    nisab_basis: NisabBasis | null;
    // A price and the day it was read travel as a PAIR, one pair per metal.
    // A single row-level date would belong to whichever price was saved last,
    // so re-quoting gold would re-date a silver price nobody had looked at for
    // months — and the calculator, which resolves the price per metal, would
    // publish that silver figure as current.
    gold_price_per_gram_minor: number | null;
    gold_price_quoted_on: string | null;
    gold_price_quoted_from: string | null;
    silver_price_per_gram_minor: number | null;
    silver_price_quoted_on: string | null;
    silver_price_quoted_from: string | null;
    updated_by_user_id: number | null;
    created_at: string;
    updated_at: string;
};

// GET/POST /api/admin/masjids/{id}/zakat-settings. `nisab` is resolved by the
// SAME server code the public endpoint answers donors from, so the admin screen
// never renders its own idea of the threshold.
export type ZakatSettingResponse = {
    setting: ZakatSetting | null;
    nisab: NisabReference;
};

// What the settings form posts. All seven keys travel every time, with explicit
// nulls for cleared fields — the server preserves an absent key, so clearing a
// price has to be an explicit null rather than an omission. masjid_id is never
// sent: the tenant guardrail owns it.
//
// A price is `number | null` and NOT `number | null | undefined`, and the number
// is whatever was typed — including 0. Mapping a typed 0 to null here would post
// "clear the organization's price" when the office meant "this price is zero",
// and the server's deliberate `min:1` refusal would never fire.
export type ZakatSettingPayload = {
    nisab_basis: NisabBasis | null;
    gold_price_per_gram_minor: number | null;
    gold_price_quoted_on: string | null;
    gold_price_quoted_from: string | null;
    silver_price_per_gram_minor: number | null;
    silver_price_quoted_on: string | null;
    silver_price_quoted_from: string | null;
};

// The eight asset buckets and three liability buckets, in the order the server
// reports them (ZakatCalculator::ASSET_KEYS / LIABILITY_KEYS). Labels are the
// plain names of the things, not categories of ruling.
export const ZAKAT_ASSET_FIELDS: { key: string; label: string; hint: string }[] = [
    { key: 'cash', label: 'Cash on hand', hint: 'Notes and coins you hold.' },
    { key: 'bank_balances', label: 'Bank balances', hint: 'Chequing, savings and money-market balances.' },
    { key: 'gold_value', label: 'Gold', hint: 'What you judge your gold to be worth. Nothing is valued for you.' },
    { key: 'silver_value', label: 'Silver', hint: 'What you judge your silver to be worth.' },
    { key: 'business_inventory', label: 'Business inventory', hint: 'Trade goods held for sale.' },
    { key: 'receivables', label: 'Money owed to you', hint: 'Amounts you expect to recover.' },
    { key: 'investments', label: 'Investments', hint: 'Shares, funds and similar holdings, at the value you determine.' },
    { key: 'other_assets', label: 'Other assets', hint: 'Anything else you count as zakatable wealth.' },
];

export const ZAKAT_LIABILITY_FIELDS: { key: string; label: string; hint: string }[] = [
    { key: 'debts_due', label: 'Debts due', hint: 'Debts you are deducting.' },
    { key: 'business_payables', label: 'Business payables', hint: 'Amounts your business owes.' },
    { key: 'other_liabilities', label: 'Other liabilities', hint: 'Anything else you are deducting.' },
];
