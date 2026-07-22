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

        $donations = Donation::withoutGlobalScopes()
            ->where('masjid_id', $masjidId)
            ->where('contact_id', $contactId)
            ->where('status', 'succeeded')
            ->whereBetween('created_at', [$start, $end])
            ->with(['fund' => fn ($q) => $q->withoutGlobalScopes(), 'receipt'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Donation $d) => $d->receipt !== null); // receipted = tax-eligible

        if ($donations->isEmpty()) {
            return null;
        }

        $gifts = [];
        $byFund = [];
        $total = 0;

        foreach ($donations as $d) {
            $eligible = (int) $d->receipt->eligible_amount;
            $fundName = $d->fund?->name ?? 'General';
            $total += $eligible;
            $byFund[$fundName] = ($byFund[$fundName] ?? 0) + $eligible;

            $gifts[] = [
                'date' => Carbon::parse($d->created_at)->format('M j, Y'),
                'fund' => $fundName,
                'amount' => $eligible,
                'serial' => (int) $d->receipt->serial_number,
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

        // One pass over the year's receipted donations, grouped by contact.
        $rows = Donation::withoutGlobalScopes()
            ->where('donations.masjid_id', $masjidId)
            ->where('donations.status', 'succeeded')
            ->whereNotNull('donations.contact_id')
            ->whereBetween('donations.created_at', [$start, $end])
            ->join('donation_receipts', 'donation_receipts.donation_id', '=', 'donations.id')
            ->join('contacts', 'contacts.id', '=', 'donations.contact_id')
            ->groupBy('donations.contact_id', 'contacts.first_name', 'contacts.last_name', 'contacts.email', 'donations.currency')
            ->selectRaw('donations.contact_id as contact_id,
                         contacts.first_name, contacts.last_name, contacts.email,
                         donations.currency as currency,
                         SUM(donation_receipts.eligible_amount) as total_eligible,
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
