<?php

namespace App\Services\Schools;

use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\ReportCard;
use App\Models\ReportCardMark;
use App\Support\DesignTokens;
use App\Support\PerformanceLevel;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * A report card as a document a family keeps.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS ONE USES MPDF WHEN THE RECEIPTS USE DOMPDF
 * ---------------------------------------------------------------------------
 *
 * dompdf is already installed and already renders the donation receipt and the
 * annual statement, so reaching for a second engine needs a reason. The reason
 * is Arabic, and it is specific rather than theoretical.
 *
 * dompdf implements no bidi and no shaping — `unicode_bidi` exists in its Style
 * class as a parsed property with a hardcoded default and nothing acts on it.
 * Its bundled DejaVu Sans *does* carry Arabic glyphs, so the failure is not a
 * missing-glyph box or an exception. Rendering `سورة البقرة` through it on this
 * server produced legible-looking Arabic laid out left to right, with the words
 * in reverse order. Text that looks like text and says something else.
 *
 * Two of the fields on every card are wide open: the child's NAME, which the
 * office types from whatever the family gave, and up to ~26,000 characters of
 * teacher comment, validated as nothing but `string`. Nothing between the
 * teacher's keyboard and this renderer would notice Arabic, and no test or
 * demo fixture in this repo contains an Arabic name, so a mangled one would
 * ship unseen.
 *
 * That matters more here than anywhere else in the application because of what
 * this document is. The marks table stores `subject` and `criterion` as frozen
 * text, and publication freezes the attendance figures, both for the reason the
 * migration gives: a document that rewrites itself is not a record. A record
 * that silently reverses a child's name fails the same test. SchoolRecordsCsv
 * already made this call in writing for the CSV export of these very fields —
 * "this system's whole point includes Arabic ... many student names are" — and
 * a PDF that cannot carry what the CSV was hardened to carry would be an
 * unforced inconsistency.
 *
 * The receipts stay on dompdf. They work, donor names are Latin in practice,
 * and one renderer per document type is cheaper than a risky cutover.
 */
class ReportCardPdfService
{
    /**
     * Render the card. Returns raw PDF bytes.
     *
     * The caller is responsible for having decided this card may be seen — the
     * family realm applies `published()` as a scope and the teacher realm the
     * class gate. Nothing here re-checks, and nothing here writes.
     */
    public function render(ReportCard $card, GroupMembership $membership): string
    {
        $mpdf = new Mpdf([
            'format' => 'Letter',
            'margin_top' => 14,
            'margin_bottom' => 16,
            'margin_left' => 14,
            'margin_right' => 14,
            // mpdf writes font subsets and image scratch here. Kept inside
            // storage rather than the package directory, which is not writable
            // once composer install runs as root on the deploy host.
            'tempDir' => $this->tempDir(),
            // THE TWO SETTINGS THIS WHOLE CHOICE WAS ABOUT. autoScriptToLang
            // detects the script of each run, autoLangToFont then selects a font
            // that can actually shape it — so an Arabic name inside an otherwise
            // English document is joined and ordered correctly without the
            // caller marking it up.
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        $mpdf->SetTitle($this->documentTitle($card, $membership));
        $mpdf->SetAuthor((string) ($card->masjid?->name ?? 'Manara'));
        // A report card is a record, not a web page: make it findable as one.
        $mpdf->SetSubject($card->typeLabel() . ' — ' . $card->periodLabel());

        $mpdf->WriteHTML(view('pdf.report-card', $this->data($card, $membership))->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * What the file is called when it lands in a parent's downloads folder.
     *
     * Name first, because that is what a parent scanning a folder of school
     * paperwork two years later is looking for.
     */
    public function filename(ReportCard $card, GroupMembership $membership): string
    {
        $parts = [
            $this->childName($membership),
            $card->typeLabel(),
            $card->periodLabel(),
        ];

        $slug = Str::slug(implode(' ', array_filter($parts))) ?: 'report-card';

        return $slug . '.pdf';
    }

    /** @return array<string, mixed> */
    private function data(ReportCard $card, GroupMembership $membership): array
    {
        $masjid = $card->masjid ?? Masjid::withoutGlobalScopes()->find($card->masjid_id);
        $marks = $card->marks()->orderBy('position')->get();

        return [
            'schoolName' => (string) ($masjid?->name ?? ''),
            'logo' => $this->logoDataUri($masjid),
            'palette' => DesignTokens::resolve($masjid?->themeSettings),

            'childName' => $this->childName($membership),
            'gradeLabel' => $card->grade_label,
            'typeLabel' => $card->typeLabel(),
            'periodLabel' => $card->periodLabel(),
            'publishedAt' => $card->published_at?->format('F j, Y'),

            // Present already INCLUDES the late days, and the document says so
            // rather than leaving a parent to work out whether the three
            // figures are meant to sum. Null is "no figure recorded", which is
            // not a zero, so the block is omitted entirely rather than printing
            // zeroes that would read as "never absent".
            'attendance' => $this->attendance($card),

            'subjects' => $marks->where('kind', ReportCardMark::KIND_ACADEMIC)
                ->groupBy('subject')
                ->map(fn ($rows, $subject): array => [
                    'subject' => $subject,
                    'criteria' => $rows->map(fn (ReportCardMark $m) => $this->mark($m))->values(),
                ])->values(),

            'behaviours' => $marks->where('kind', ReportCardMark::KIND_BEHAVIOUR)
                ->map(fn (ReportCardMark $m) => $this->mark($m))->values(),

            'teacherComment' => $card->teacher_comment,

            // The key travels with the document, exactly as it does on screen.
            // A printed card a parent cannot decode has communicated nothing,
            // and there is no console to check on paper.
            'levels' => PerformanceLevel::key(),
        ];
    }

    /**
     * The frozen figures, or nothing at all.
     *
     * `publish()` always writes integers, so a school that does not take the
     * register produces 0/0/0 rather than nulls — and printing "Present 0 ·
     * Absent 0" on a report card states that the child attended nothing, which
     * is a different and much worse claim than saying nothing. All three at zero
     * means no register was kept for the period, so the block is omitted.
     *
     * @return array{present:int, absent:int, late:int}|null
     */
    private function attendance(ReportCard $card): ?array
    {
        $present = (int) $card->days_present;
        $absent = (int) $card->days_absent;
        $late = (int) $card->days_late;

        if ($card->days_present === null || ($present === 0 && $absent === 0 && $late === 0)) {
            return null;
        }

        return ['present' => $present, 'absent' => $absent, 'late' => $late];
    }

    /** @return array<string, mixed> */
    private function mark(ReportCardMark $m): array
    {
        return [
            'criterion' => $m->criterion,
            'level' => $m->level,
            // NULL is "not assessed" — true of a child who joined in week eight
            // — and is never printed as a zero.
            'levelLabel' => $m->level === null ? 'Not assessed' : PerformanceLevel::label($m->level),
            'comment' => $m->comment,
        ];
    }

    private function childName(GroupMembership $membership): string
    {
        $contact = $membership->contact;

        $name = trim(implode(' ', array_filter([
            $contact?->first_name,
            $contact?->last_name,
        ])));

        return $name !== '' ? $name : 'Student';
    }

    /**
     * The school's own mark, embedded rather than linked.
     *
     * Read from the media library as bytes: a renderer fetching a URL mid-render
     * makes the document depend on the network, and a slow or unreachable host
     * would silently produce a logo-less report card.
     *
     * SVG is skipped rather than attempted — the same call Letterhead makes for
     * the receipts. A missing logo degrades to the school's name in its own
     * colour, which is a document that still looks deliberate.
     */
    private function logoDataUri(?Masjid $masjid): ?string
    {
        $media = $masjid?->getFirstMedia('logos');

        if ($media === null || ! in_array($media->mime_type, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return null;
        }

        $path = $media->getPath();

        if (! is_readable($path)) {
            return null;
        }

        return 'data:' . $media->mime_type . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    private function documentTitle(ReportCard $card, GroupMembership $membership): string
    {
        return $this->childName($membership) . ' — ' . $card->typeLabel() . ', ' . $card->periodLabel();
    }

    private function tempDir(): string
    {
        $dir = storage_path('app/private/mpdf');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
