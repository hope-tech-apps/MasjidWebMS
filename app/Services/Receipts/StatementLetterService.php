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
    public function __construct(private AnnualStatementService $statements)
    {
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

        $data = [
            'logo' => $this->logoDataUri($masjid),
            'masjidName' => $masjid?->name ?? 'Your masjid',
            'address' => $masjid?->address ?: '',
            'locale' => $masjid?->mailing_locale ?: '',
            'phone' => $this->displayPhone($masjid?->phone),
            'website' => $this->displayWebsite($masjid?->website_link),
            'taxId' => $masjid?->tax_id,
            'signatory' => $masjid?->statement_signatory ?: ($masjid?->name ?? ''),
            'date' => Carbon::now()->format('F jS, Y'),
            'donorName' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'Valued donor',
            'year' => $statement['year'],
            'currency' => $statement['currency'],
            'totalEligible' => $money($statement['total_eligible']),
            'giftCount' => $statement['gift_count'],
            'gifts' => array_map(fn ($g) => [
                'date' => $g['date'], 'fund' => $g['fund'], 'amount' => $money($g['amount']),
            ], $statement['gifts']),
        ];

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

    /**
     * Masjid logo as a base64 data URI for dompdf, or null.
     *
     * dompdf embeds JPEG without the GD extension but needs GD for PNG. The droplet
     * has no GD, so we prefer a per-masjid JPEG "statement asset"
     * (storage/app/statement-assets/masjid-{id}-logo.jpg); failing that, the media
     * logo is used only when it is already a JPEG.
     */
    private function logoDataUri(?Masjid $masjid): ?string
    {
        if (! $masjid) {
            return null;
        }

        foreach (['jpg', 'jpeg'] as $ext) {
            $path = storage_path("app/statement-assets/masjid-{$masjid->id}-logo.{$ext}");
            if (is_readable($path)) {
                return 'data:image/jpeg;base64,' . base64_encode(file_get_contents($path));
            }
        }

        $media = $masjid->getFirstMedia('logo');
        if ($media && is_readable($media->getPath()) && str_contains((string) $media->mime_type, 'jpeg')) {
            return 'data:image/jpeg;base64,' . base64_encode(file_get_contents($media->getPath()));
        }

        return null;
    }

    private function displayPhone(?string $phone): ?string
    {
        return $phone ? trim($phone) : null;
    }

    /** Strip scheme/www for the letterhead line. */
    private function displayWebsite(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        return preg_replace('#^https?://(www\.)?#i', '', rtrim($url, '/'));
    }
}
