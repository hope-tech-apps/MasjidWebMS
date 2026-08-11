<?php

namespace App\Services\Receipts;

use App\Models\Masjid;

/**
 * Letterhead — the masjid's own branding for a printed tax document.
 *
 * Both receipt documents are letters from the organization, not from the
 * platform: the year-end giving statement (StatementLetterService) and the
 * per-donation receipt (DonationReceiptPdfService). They resolve the logo, the
 * address block and the EIN through here so a masjid that fixes its letterhead
 * fixes BOTH documents, and the two can never disagree about who issued them.
 *
 * Extracted verbatim from StatementLetterService when the per-donation receipt
 * PDF landed; the statement letter's output is unchanged.
 */
class Letterhead
{
    /**
     * The letterhead block for a masjid, keyed for a PDF blade.
     *
     * @return array{logo:?string, masjidName:string, address:string, locale:string, phone:?string, website:?string, taxId:?string, signatory:string}
     */
    public function forMasjid(?Masjid $masjid): array
    {
        return [
            'logo' => $this->logoDataUri($masjid),
            'masjidName' => $masjid?->name ?? 'Your masjid',
            'address' => $masjid?->address ?: '',
            'locale' => $masjid?->mailing_locale ?: '',
            'phone' => $this->displayPhone($masjid?->phone),
            'website' => $this->displayWebsite($masjid?->website_link),
            'taxId' => $masjid?->tax_id,
            'signatory' => $masjid?->statement_signatory ?: ($masjid?->name ?? ''),
        ];
    }

    /**
     * Masjid logo as a base64 data URI for dompdf, or null.
     *
     * A hand-curated per-masjid "statement asset"
     * (storage/app/statement-assets/masjid-{id}-logo.{jpg,jpeg,png}) takes
     * precedence — this is what a masjid whose media logo is an SVG (e.g. a
     * base64-PNG-wrapped SVG, which dompdf can't rasterize) uses. Otherwise the
     * masjid's media logo is embedded directly when it is a JPEG or PNG. The prod
     * host now has the GD extension, so PNG logos render natively — a plain-PNG
     * masjid needs no curated asset. SVG media logos are still skipped (dompdf has
     * no SVG rasterizer); such masjids should drop a JPEG/PNG into statement-assets.
     */
    public function logoDataUri(?Masjid $masjid): ?string
    {
        if (! $masjid) {
            return null;
        }

        foreach (['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'] as $ext => $mime) {
            $path = storage_path("app/statement-assets/masjid-{$masjid->id}-logo.{$ext}");
            if (is_readable($path)) {
                return "data:{$mime};base64," . base64_encode(file_get_contents($path));
            }
        }

        $media = $masjid->getFirstMedia('logo');
        if ($media && is_readable($media->getPath())) {
            $mime = (string) $media->mime_type;
            if (str_contains($mime, 'jpeg') || str_contains($mime, 'png')) {
                return "data:{$mime};base64," . base64_encode(file_get_contents($media->getPath()));
            }
        }

        return null;
    }

    public function displayPhone(?string $phone): ?string
    {
        return $phone ? trim($phone) : null;
    }

    /** Strip scheme/www for the letterhead line. */
    public function displayWebsite(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        return preg_replace('#^https?://(www\.)?#i', '', rtrim($url, '/'));
    }
}
