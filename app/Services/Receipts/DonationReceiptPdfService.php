<?php

namespace App\Services\Receipts;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Masjid;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * Renders ONE issued donation receipt as a printable PDF, on the masjid's own
 * letterhead — the same dompdf + blade path StatementLetterService uses for the
 * year-end letter, sharing Letterhead so both documents carry one masthead.
 *
 * Every figure is READ OFF THE STORED donation_receipts row — serial, issue
 * date, gross, advantage, eligible, currency, jurisdiction. Nothing is
 * recomputed here: the row IS the record of what was issued (gap-free serial,
 * eligible = gross − advantage, per .claude/rules/stripe-payments.md), and a
 * PDF that recalculated any of it could contradict the receipt the donor was
 * already given. Rendering is strictly read-only — it never issues, re-issues
 * or mutates a receipt.
 *
 * Related rows are loaded UNSCOPED because this also runs from the Stripe
 * webhook, which is unbound (same reason ReceiptService filters masjid_id by
 * hand). All amounts stay integer minor units until the format at render.
 */
class DonationReceiptPdfService
{
    public function __construct(private Letterhead $letterhead)
    {
    }

    /** The receipt as PDF bytes. */
    public function pdfFor(DonationReceipt $receipt): string
    {
        $donation = Donation::withoutMasjidScope()->find($receipt->donation_id);
        $masjid = Masjid::withoutGlobalScopes()->find($receipt->masjid_id);
        $fund = $donation?->fund()->withoutGlobalScopes()->first();
        $contact = $donation?->contact_id
            ? Contact::withoutMasjidScope()->find($donation->contact_id)
            : null;

        $money = fn (int $cents) => number_format($cents / 100, 2);
        $donorName = trim(($contact?->first_name ?? '') . ' ' . ($contact?->last_name ?? ''));

        $advantage = (int) $receipt->advantage_amount;

        $data = array_merge($this->letterhead->forMasjid($masjid), [
            'serial' => (int) $receipt->serial_number,
            'issueDate' => Carbon::parse($receipt->issue_date)->format('F jS, Y'),
            'giftDate' => $donation
                ? Carbon::parse($donation->donated_at ?? $donation->created_at)->format('F jS, Y')
                : null,
            'donorName' => $donorName !== '' ? $donorName : 'Valued donor',
            'fundName' => $fund?->name ?? 'General',
            'currency' => strtoupper((string) $receipt->currency),
            'grossAmount' => $money((int) $receipt->gross_amount),
            'advantageAmount' => $money($advantage),
            'hasAdvantage' => $advantage > 0,
            'eligibleAmount' => $money((int) $receipt->eligible_amount),
            'jurisdiction' => (string) $receipt->jurisdiction,
            'reference' => (string) ($donation?->uuid ?? ''),
        ]);

        return Pdf::loadView('pdf.donation-receipt', $data)
            ->setPaper('letter')
            ->output();
    }

    /**
     * Suggested filename, keyed by the masjid's own receipt serial — that is the
     * number the donor and the bookkeeper both quote.
     */
    public function filename(DonationReceipt $receipt): string
    {
        return 'donation-receipt-' . (int) $receipt->serial_number . '.pdf';
    }
}
