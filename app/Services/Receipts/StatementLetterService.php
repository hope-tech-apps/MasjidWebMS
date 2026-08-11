<?php

namespace App\Services\Receipts;

use App\Models\Masjid;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * Renders a donor's year-end giving statement as a 501(c)(3) tax LETTER PDF, in
 * the masjid's own letterhead (logo, address, EIN, signatory). Used for both the
 * download and the emailed attachment — most donors have no email, so a printable
 * letter is the primary delivery.
 */
class StatementLetterService
{
    public function __construct(
        private AnnualStatementService $statements,
        private Letterhead $letterhead,
    ) {
    }

    /**
     * Full statement letter PDF for one donor/year, or null when they have no
     * receiptable giving that year.
     */
    public function pdfFor(int $masjidId, int $contactId, int $year): ?string
    {
        $statement = $this->statements->forContact($masjidId, $contactId, $year);
        if (! $statement) {
            return null;
        }

        $masjid = Masjid::withoutGlobalScopes()->find($masjidId);
        $contact = $statement['contact'];
        $money = fn (int $cents) => number_format($cents / 100, 2);

        // Logo / address block / EIN / signatory come from the shared letterhead
        // so the per-donation receipt PDF prints the same masthead.
        $data = array_merge($this->letterhead->forMasjid($masjid), [
            'date' => Carbon::now()->format('F jS, Y'),
            'donorName' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'Valued donor',
            'year' => $statement['year'],
            'currency' => $statement['currency'],
            'totalEligible' => $money($statement['total_eligible']),
            'giftCount' => $statement['gift_count'],
            'gifts' => array_map(fn ($g) => [
                'date' => $g['date'], 'fund' => $g['fund'], 'amount' => $money($g['amount']),
            ], $statement['gifts']),
        ]);

        return Pdf::loadView('pdf.annual-statement', $data)
            ->setPaper('letter')
            ->output();
    }

    /** Suggested filename for a donor/year letter. */
    public function filename(string $donorName, int $year): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', trim($donorName)) ?: 'donor';

        return "{$year}-giving-statement-{$slug}.pdf";
    }
}
