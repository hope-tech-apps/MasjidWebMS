/**
 * The English names of `PaletteContrast::report()`'s pair keys (app/Support/
 * Studio/PaletteContrast.php, R16). The keys and every figure are the
 * server's; only the names are here, in one place, because two screens print
 * them: the Palette report table (components/super/studio/foundation/
 * PaletteReport.vue) and the live Brand card's save dialog, which lists the
 * pairs that fail (components/super/studio/live/LiveBrandCard.vue, W2 S9).
 *
 * No runtime imports, so node can run it.
 */
import type { StudioPaletteReport } from '../types/data/Studio';

export const PAIR_LABELS: Record<string, string> = {
    text_on_background: 'Body text on the background',
    on_primary: 'Text on the primary colour',
    on_secondary: 'Text on the secondary colour',
    on_accent: 'Text on the accent colour',
    primary_on_background: 'Primary colour on the background',
    accent_on_background: 'Accent colour on the background',
};

/** A pair's name; an unknown key is shown as it came. */
export function pairLabel(key: string): string {
    return PAIR_LABELS[key] ?? key;
}

/**
 * The failing text pairs of the report for the colours about to be saved, as
 * dialog lines; null when there is no report that describes them. A preview
 * still in flight, or one that failed and left the previous colours' report
 * behind, is not these colours' report, so the caller says the check has not
 * caught up rather than listing stale pairs (or none).
 */
export function failingPairLines(
    report: StudioPaletteReport | null,
    state: { queued: boolean; loading: boolean; error: string | null },
): string[] | null {
    if (!report || state.queued || state.loading || state.error) return null;
    if (report.valid) return [];

    return report.blocking_failures.map((key) => {
        const pair = report.pairs.find((candidate) => candidate.key === key);
        const ratio = pair?.ratio === null || pair?.ratio === undefined ? '' : ` ${pair.ratio.toFixed(2)}:1, needs ${pair.required}:1`;
        return `${pairLabel(key)}:${ratio || ' fails'}`;
    });
}
