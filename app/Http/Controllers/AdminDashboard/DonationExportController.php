<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\Masjid;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin: donation ledger CSV export — the file the accountant reconciles against.
 *
 * Deliberately a separate controller from the ledger itself: this is the only
 * donation endpoint that emits DONOR PII in bulk and money in dollars, so it is
 * worth keeping visible on its own rather than buried as a sixth method.
 *
 * Tenant-scoped by the `tenant` middleware + BelongsToMasjid — no hand-filtering
 * by $masjid_id. See .claude/rules/tenant-scoping.md.
 */
class DonationExportController extends Controller
{
    /**
     * GET .../donations/export
     *
     * Streams the CURRENT filter selection through the same query builder the
     * ledger page uses, so the export equals what the admin is looking at. The
     * response is streamed and chunked rather than assembled in memory because a
     * masjid's full history is years of rows, not a page of them.
     */
    public function export(Request $request, $masjid_id): StreamedResponse
    {
        $masjid = Masjid::findOrFail($masjid_id);

        // The masjid is handed to the filter so the date window is cut on the
        // masjid's calendar day (its timezone), exactly as the stats header cuts
        // it — and so the row is not fetched a second time to learn that.
        $query = DonationsController::filteredQuery($request, $masjid)->with(['fund', 'contact']);

        // No slug column on masjids, so derive one; a name with no latin characters
        // slugs to '' and falls back to the id.
        $filename = 'donations-'
            . (Str::slug($masjid->name) ?: $masjid->id)
            . '-' . now()->format('Y-m-d') . '.csv';

        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Date', 'Donor', 'Donor email', 'Fund', 'Amount', 'Net',
                'Currency', 'Source', 'Payment method', 'Check number', 'Status', 'Note',
            ]);

            // chunkById keeps memory flat over a full history. It paginates on the
            // primary key, so rows come out in entry order — adding a gift-date
            // ORDER BY here would break the id cursor and silently skip rows.
            $query->chunkById(500, function ($chunk) use ($out) {
                foreach ($chunk as $donation) {
                    fputcsv($out, $this->row($donation));
                }
            });

            fclose($out);
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** @return array<int,string> */
    private function row(Donation $donation): array
    {
        $contact = $donation->contact;

        return [
            // Canonical gift date: donated_at for offline history, else the row's own
            // creation for a Stripe gift, which leaves donated_at null.
            ($donation->donated_at ?? $donation->created_at)->format('Y-m-d'),
            // Blank donor is normal — an anonymous or general gift books with no contact.
            $this->csvCell($contact ? trim($contact->first_name . ' ' . $contact->last_name) : ''),
            $this->csvCell((string) ($contact->email ?? '')),
            $this->csvCell((string) ($donation->fund->name ?? '')),
            $this->dollars($donation->charged_amount),
            $this->net($donation),
            strtoupper((string) $donation->currency),
            (string) $donation->source,
            (string) $donation->payment_method,
            $this->csvCell((string) $donation->check_number),
            (string) $donation->status,
            $this->csvCell((string) $donation->note),
        ];
    }

    /**
     * What actually landed after processor fees — not always knowable at export
     * time, and a null net_amount does NOT mean the same thing for both sources:
     *
     *   - offline gift: no processor ever touched it, so the net IS the gross. A
     *     real number the treasurer can reconcile against the bank.
     *   - Stripe gift with no balance transaction fetched yet: a fee exists and is
     *     simply unknown here. Printing the gross would read as a fee-free gift and
     *     overstate the deposit, so the cell carries a word instead of a number — a
     *     word cannot be silently summed into a total, a wrong figure can.
     *
     * The word is only `pending` while settlement is still ahead of the gift; a
     * failed or refunded charge with no net has none coming, so its cell is left
     * empty rather than promising a figure that will never arrive.
     */
    private function net(Donation $donation): string
    {
        if ($donation->net_amount !== null) {
            return $this->dollars($donation->net_amount);
        }

        if ($donation->source !== 'stripe') {
            return $this->dollars($donation->charged_amount);
        }

        return in_array($donation->status, ['pending', 'succeeded'], true) ? 'pending' : '';
    }

    /**
     * Cents -> dollars at the edge, by integer maths only: money is stored in minor
     * units and $cents / 100 would introduce the float this codebase forbids.
     */
    private function dollars(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        $abs = abs($cents);

        return ($cents < 0 ? '-' : '')
            . intdiv($abs, 100) . '.'
            . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Neutralise spreadsheet formula injection: Excel and Sheets evaluate a cell
     * starting with =, +, - or @, so a donor note or name could execute when the
     * bookkeeper opens the file. A leading apostrophe makes it literal text.
     */
    private function csvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
