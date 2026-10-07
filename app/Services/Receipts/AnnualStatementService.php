<?php

namespace App\Services\Receipts;

use App\Models\Contact;
use App\Models\Donation;
use Illuminate\Support\Carbon;

/**
 * AnnualStatementService — aggregates a donor's tax-eligible giving for a calendar
 * year into a single 501(c)(3) annual statement.
 *
 * Recurring and one-time gifts are summed identically (each recurring charge is an
 * ordinary succeeded Donation with its own receipt), so nothing here special-cases
 * subscriptions. Succeeded gifts to receiptable funds count, using the receipt
 * eligible amount when present and the charged amount otherwise. Each currency
 * is totaled separately.
 *
 * Imported order history (`source = 'historical'`, legacy orders) is left
 * out of both queries. That money moved through Square or PayPal on the old Wix
 * site; a statement Manara emails is a tax document, and listing those gifts on
 * it would state that Manara recorded payments it never saw (DECISIONS.md
 * 2026-09-25, "Wix order history"). The history stays visible on the donor's
 * record; only the statement leaves it out.
 *
 * Runs from admin (tenant-bound) and could run unbound in a future scheduled job,
 * so it filters masjid_id explicitly rather than leaning on the global scope.
 *
 * All amounts are integer minor units (cents).
 */
class AnnualStatementService
{
    /**
     * One donor's statement for a year.
     *
     * Single-currency statements retain the original payload. Mixed statements
     * have null currency/total_eligible, currency-labeled gifts, and a currencies
     * list with independent totals, gift counts, gifts and fund totals.
     *
     * @return array{
     *   contact: Contact,
     *   year: int,
     *   currency: ?string,
     *   total_eligible: ?int,
     *   gift_count: int,
     *   gifts: array<int, array{date:string, fund:string, amount:int, serial:?int, currency?:string}>,
     *   by_fund: array<string, int>,
     *   currencies?: array<int, array{currency:string, total_eligible:int, gift_count:int, gifts:array, by_fund:array<string,int>}>
     * }|null null when the donor gave nothing receiptable that year
     */
    public function forContact(int $masjidId, int $contactId, int $year): ?array
    {
        $contact = Contact::withoutMasjidScope()
            ->where('masjid_id', $masjidId)
            ->find($contactId);

        if (! $contact) {
            return null;
        }

        [$start, $end] = $this->yearBounds($year);

        // Tax-eligible giving = succeeded donations to a RECEIPTABLE fund, dated by
        // the real gift date (donated_at for imported/offline history, else the
        // entry date). A gift counts whether or not a formal receipt row exists:
        // Stripe gifts have one; imported historical gifts don't, but a genuine
        // donation to a receiptable fund is still eligible. Eligible amount comes
        // from the receipt when present, else the amount given.
        $donations = Donation::withoutGlobalScopes()
            ->where('masjid_id', $masjidId)
            ->where('contact_id', $contactId)
            ->where('status', 'succeeded')
            // Imported Wix history (paid through Square/PayPal before Manara) is
            // never on a Manara tax statement — see the class docblock.
            ->withoutHistorical()
            ->whereRaw('COALESCE(donated_at, created_at) BETWEEN ? AND ?', [$start, $end])
            ->whereHas('fund', fn ($q) => $q->withoutGlobalScopes()->where('receiptable', true))
            ->with(['fund' => fn ($q) => $q->withoutGlobalScopes(), 'receipt'])
            ->orderByRaw('COALESCE(donated_at, created_at)')
            ->get();

        if ($donations->isEmpty()) {
            return null;
        }

        $gifts = [];
        $currencies = [];

        foreach ($donations as $d) {
            $eligible = $d->receipt ? (int) $d->receipt->eligible_amount : (int) $d->charged_amount;
            $fundName = $d->fund?->name ?? 'General';
            $currency = strtoupper((string) $d->currency);
            $currencies[$currency] ??= [
                'currency' => $currency, 'total_eligible' => 0, 'gift_count' => 0,
                'gifts' => [], 'by_fund' => [],
            ];
            $section = &$currencies[$currency];
            $section['total_eligible'] += $eligible;
            $section['gift_count']++;
            $section['by_fund'][$fundName] = ($section['by_fund'][$fundName] ?? 0) + $eligible;

            $gift = [
                'date' => Carbon::parse($d->donated_at ?? $d->created_at)->format('M j, Y'),
                'fund' => $fundName,
                'amount' => $eligible,
                'serial' => $d->receipt ? (int) $d->receipt->serial_number : null,
            ];
            $section['gifts'][] = $gift;
            $gifts[] = $gift + ['currency' => $currency];
            unset($section);
        }

        $single = count($currencies) === 1 ? reset($currencies) : null;

        $statement = [
            'contact' => $contact,
            'year' => $year,
            'currency' => $single['currency'] ?? null,
            'total_eligible' => $single['total_eligible'] ?? null,
            'gift_count' => $donations->count(),
            'gifts' => $single['gifts'] ?? $gifts,
            'by_fund' => $single['by_fund'] ?? [],
        ];

        if (! $single) {
            $statement['currencies'] = array_values($currencies);
        }

        return $statement;
    }

    /**
     * Report row per donor and currency with receiptable giving in the year — the admin
     * summary that drives "email statement" / "email all".
     *
     * @return array<int, array{contact_id:int, name:string, email:?string, total_eligible:int, gift_count:int, currency:string}>
     */
    public function summaryForYear(int $masjidId, int $year): array
    {
        [$start, $end] = $this->yearBounds($year);

        // One pass over the year's tax-eligible giving (succeeded, to a receiptable
        // fund), grouped by donor. Uses the real gift date and sums the amount
        // given (eligible == gross in this system; advantage is always 0).
        $rows = Donation::withoutGlobalScopes()
            ->where('donations.masjid_id', $masjidId)
            ->where('donations.status', 'succeeded')
            ->withoutHistorical()
            ->whereNotNull('donations.contact_id')
            ->whereRaw('COALESCE(donations.donated_at, donations.created_at) BETWEEN ? AND ?', [$start, $end])
            ->join('funds', 'funds.id', '=', 'donations.fund_id')
            ->where('funds.receiptable', true)
            ->join('contacts', 'contacts.id', '=', 'donations.contact_id')
            ->groupBy('donations.contact_id', 'contacts.first_name', 'contacts.last_name', 'contacts.email', 'donations.currency')
            ->selectRaw('donations.contact_id as contact_id,
                         contacts.first_name, contacts.last_name, contacts.email,
                         donations.currency as currency,
                         SUM(donations.charged_amount) as total_eligible,
                         COUNT(*) as gift_count')
            ->orderByDesc('total_eligible')
            ->get();

        return $rows->map(fn ($r) => [
            'contact_id' => (int) $r->contact_id,
            'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: 'Donor',
            'email' => $r->email,
            'total_eligible' => (int) $r->total_eligible,
            'gift_count' => (int) $r->gift_count,
            'currency' => strtoupper((string) $r->currency),
        ])->all();
    }

    /** @return array{0:Carbon,1:Carbon} inclusive start / exclusive-ish end of the calendar year */
    private function yearBounds(int $year): array
    {
        return [
            Carbon::create($year, 1, 1, 0, 0, 0),
            Carbon::create($year, 12, 31, 23, 59, 59),
        ];
    }
}
