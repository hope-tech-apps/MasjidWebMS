<?php

use App\Enums\SectionType;
use App\Models\DonationLink;
use App\Models\MasjidAbout;
use App\Models\Section;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 1 content-unification — NON-DESTRUCTIVE backfill.
 *
 * Goal: the dedicated models (MasjidAbout, DonationLink) become the single
 * source the website renders. For existing page-builder sections that still
 * carry their own copy, seed the model FROM the section ONLY when the model is
 * empty. If the model already has content, keep the model (the section copy is
 * simply ignored from now on by the Web V1 serializer). Never delete anything.
 *
 * Per field, three outcomes:
 *   - model field empty, section has a value  -> COPY section -> model (backfill)
 *   - model field already has a value          -> KEEP model (section ignored)
 *   - BOTH non-empty AND DIFFERENT             -> CONFLICT: keep model, REPORT it
 *
 * Field mapping:
 *   about_us       -> MasjidAbout.about   (content.text), aboutImage handled by admin, not copied here
 *   mission_vision -> MasjidAbout.mission / .vision   (from content.items[] cards)
 *   donation       -> DonationLink.title (content.title), .message (content.subtitle), .link (content.link)
 *
 * The report is printed to STDOUT (and the migration log) so a human can review
 * every conflict. down() is a no-op: we never created destructive state, and we
 * must not undo a legitimate backfill.
 */
return new class extends Migration
{
    /** @var array<int,string> Lines accumulated for the end-of-run report. */
    private array $report = [];

    private int $backfilled = 0;
    private int $kept = 0;
    private int $conflicts = 0;

    public function up(): void
    {
        $this->line('');
        $this->line('=== Phase 1 backfill: section content -> dedicated models ===');

        // Group all relevant sections by masjid so we touch each model once.
        $sections = Section::query()
            ->whereIn('section_type', [
                SectionType::ABOUT_US->value,
                SectionType::MISSION_VISION->value,
                SectionType::DONATION->value,
            ])
            ->orderBy('masjid_id')
            ->get();

        if ($sections->isEmpty()) {
            $this->line('No about_us / mission_vision / donation sections found. Nothing to backfill.');
            $this->flushReport();
            return;
        }

        foreach ($sections->groupBy('masjid_id') as $masjidId => $masjidSections) {
            $masjidId = (int) $masjidId;

            foreach ($masjidSections as $section) {
                $content = $this->decodeContent($section);

                switch ($section->section_type) {
                    case SectionType::ABOUT_US:
                        $this->backfillAbout($masjidId, $section->id, $content);
                        break;
                    case SectionType::MISSION_VISION:
                        $this->backfillMissionVision($masjidId, $section->id, $content);
                        break;
                    case SectionType::DONATION:
                        $this->backfillDonation($masjidId, $section->id, $content);
                        break;
                }
            }
        }

        $this->line('');
        $this->line(sprintf(
            'Summary: %d field(s) backfilled, %d kept (model already had content), %d CONFLICT(s) for manual review.',
            $this->backfilled,
            $this->kept,
            $this->conflicts
        ));
        $this->line('=== End Phase 1 backfill ===');
        $this->flushReport();
    }

    public function down(): void
    {
        // Intentionally non-destructive: a backfill copies data forward into the
        // canonical model. Rolling it back would risk deleting content that may
        // since have been edited. No-op by design.
    }

    /* -------------------------------------------------------------------- */

    private function backfillAbout(int $masjidId, int $sectionId, array $content): void
    {
        $sectionAbout = $this->str($content['text'] ?? null);
        if ($sectionAbout === null) {
            return; // nothing to copy
        }

        $about = $this->firstOrNewAbout($masjidId);
        $this->applyField($about, 'about', $sectionAbout, "MasjidAbout.about (masjid #{$masjidId}, about_us section #{$sectionId})");
        $this->persist($about);
    }

    private function backfillMissionVision(int $masjidId, int $sectionId, array $content): void
    {
        // mission_vision stores items[] cards; map them to mission/vision text.
        [$missionText, $visionText] = $this->extractMissionVision($content);

        if ($missionText === null && $visionText === null) {
            return;
        }

        $about = $this->firstOrNewAbout($masjidId);

        if ($missionText !== null) {
            $this->applyField($about, 'mission', $missionText, "MasjidAbout.mission (masjid #{$masjidId}, mission_vision section #{$sectionId})");
        }
        if ($visionText !== null) {
            $this->applyField($about, 'vision', $visionText, "MasjidAbout.vision (masjid #{$masjidId}, mission_vision section #{$sectionId})");
        }

        $this->persist($about);
    }

    private function backfillDonation(int $masjidId, int $sectionId, array $content): void
    {
        $title   = $this->str($content['title'] ?? null);
        $message = $this->str($content['subtitle'] ?? null);
        $link    = $this->str($content['link'] ?? null);

        if ($title === null && $message === null && $link === null) {
            return;
        }

        // donation_links.link is NOT NULL; seed an empty string for a fresh row
        // so a section that only carries a title/message can still be backfilled.
        $donation = DonationLink::where('masjid_id', $masjidId)->first()
            ?? new DonationLink(['masjid_id' => $masjidId, 'link' => '']);

        if ($title !== null) {
            $this->applyField($donation, 'title', $title, "DonationLink.title (masjid #{$masjidId}, donation section #{$sectionId})");
        }
        if ($message !== null) {
            $this->applyField($donation, 'message', $message, "DonationLink.message (masjid #{$masjidId}, donation section #{$sectionId})");
        }
        if ($link !== null) {
            $this->applyField($donation, 'link', $link, "DonationLink.link (masjid #{$masjidId}, donation section #{$sectionId})");
        }

        // We only reach here when at least one field was present to copy, so a
        // brand-new row always has a backfilled field. persist() saves when the
        // row is new or any field changed.
        $this->persist($donation);
    }

    /* -------------------------------------------------------------------- */

    /**
     * Apply one field with the non-destructive policy and record the outcome.
     */
    private function applyField($model, string $field, string $sectionValue, string $label): void
    {
        $modelValue = $this->str($model->{$field} ?? null);

        if ($modelValue === null) {
            // Model empty -> backfill from section.
            $model->{$field} = $sectionValue;
            $this->backfilled++;
            $this->line("  [BACKFILL] {$label}");
            return;
        }

        if ($modelValue === $sectionValue) {
            // Identical -> nothing to do; model already authoritative.
            $this->kept++;
            return;
        }

        // Both non-empty and DIFFERENT -> conflict. Keep the model, report it.
        $this->conflicts++;
        $this->line("  [CONFLICT] {$label}: model and section differ — KEEPING MODEL. Review manually.");
        $this->line("             model:   " . $this->preview($modelValue));
        $this->line("             section: " . $this->preview($sectionValue));
    }

    private function firstOrNewAbout(int $masjidId): MasjidAbout
    {
        $existing = MasjidAbout::where('masjid_id', $masjidId)->first();
        if ($existing) {
            return $existing;
        }

        // about/mission/vision are NOT NULL in the schema; seed empty strings so
        // creating a fresh row to hold a single backfilled field is valid.
        return new MasjidAbout([
            'masjid_id' => $masjidId,
            'about' => '',
            'mission' => '',
            'vision' => '',
        ]);
    }

    private function persist($model): void
    {
        if (!$model->exists || $model->isDirty()) {
            $model->save();
        }
    }

    /**
     * mission_vision content can take two shapes; support both:
     *  - items[] with a `type` of 'mission'/'vision'
     *  - items[] positional (first = mission, second = vision)
     * Card text is read from `content` or `text`.
     *
     * @return array{0:?string,1:?string}  [missionText, visionText]
     */
    private function extractMissionVision(array $content): array
    {
        $items = $content['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            return [null, null];
        }

        $mission = null;
        $vision = null;

        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = $this->str($item['content'] ?? ($item['text'] ?? null));
            if ($text === null) {
                continue;
            }
            $type = strtolower((string) ($item['type'] ?? ($item['title'] ?? '')));

            if (str_contains($type, 'mission')) {
                $mission ??= $text;
            } elseif (str_contains($type, 'vision')) {
                $vision ??= $text;
            } elseif ($i === 0 && $mission === null) {
                $mission = $text;
            } elseif ($vision === null) {
                $vision = $text;
            }
        }

        return [$mission, $vision];
    }

    private function decodeContent(Section $section): array
    {
        // Read the raw DB value to avoid the model accessor's page-link side
        // effects; we only need the stored text.
        $raw = $section->getRawOriginal('content');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? $decoded : [];
    }

    private function str($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function preview(string $value): string
    {
        $oneLine = preg_replace('/\s+/', ' ', $value);

        return mb_strlen($oneLine) > 80 ? mb_substr($oneLine, 0, 77) . '...' : $oneLine;
    }

    private function line(string $message): void
    {
        $this->report[] = $message;
    }

    private function flushReport(): void
    {
        $text = implode("\n", $this->report);
        // Print to console for `php artisan migrate` operators.
        echo $text . "\n";
        // Also drop it in the log for audit.
        if (function_exists('logger')) {
            logger()->info("[Phase1 backfill]\n" . $text);
        }
    }
};
