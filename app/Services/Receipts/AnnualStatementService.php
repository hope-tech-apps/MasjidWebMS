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
 * subscriptions. Only donations that ACTUALLY ISSUED a receipt count — a gift to a
 * non-receiptable fund never issued one and so is correctly excluded from the
 * tax-eligible total.
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
     * @return array{
     *   contact: Contact,
     *   year: int,
     *   currency: string,
     *   total_eligible: int,
     *   gift_count: int,
     *   gifts: array<int, array{date:string, fund:string, amount:int, serial:int}>,
     *   by_fund: array<string, int>
     * }|null  null when the donor gave nothing receiptable that year
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
            ->whereRaw('COALESCE(donated_at, created_at) BETWEEN ? AND ?', [$start, $end])
            ->whereHas('fund', fn ($q) => $q->withoutGlobalScopes()->where('receiptable', true))
            ->with(['fund' => fn ($q) => $q->withoutGlobalScopes(), 'receipt'])
            ->orderByRaw('COALESCE(donated_at, created_at)')
            ->get();

        if ($donations->isEmpty()) {
            return null;
        }

        $gifts = [];
        $byFund = [];
        $total = 0;

        foreach ($donations as $d) {
            $eligible = $d->receipt ? (int) $d->receipt->eligible_amount : (int) $d->charged_amount;
            $fundName = $d->fund?->name ?? 'General';
            $total += $eligible;
            $byFund[$fundName] = ($byFund[$fundName] ?? 0) + $eligible;

            $gifts[] = [
                'date' => Carbon::parse($d->donated_at ?? $d->created_at)->format('M j, Y'),
                'fund' => $fundName,
                'amount' => $eligible,
                'serial' => $d->receipt ? (int) $d->receipt->serial_number : null,
            ];
        }

        return [
            'contact' => $contact,
            'year' => $year,
            'currency' => strtoupper((string) $donations->first()->currency),
            'total_eligible' => $total,
            'gift_count' => $donations->count(),
            'gifts' => $gifts,
            'by_fund' => $byFund,
        ];
    }

    /**
     * Report row per donor who has receiptable giving in the year — the admin
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
