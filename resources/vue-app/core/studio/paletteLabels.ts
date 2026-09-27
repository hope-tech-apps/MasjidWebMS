/**
 * The English names of `PaletteContrast::report()`'s pair keys (app/Support/
 * Studio/PaletteContrast.php, R16). The keys and every figure are the
 * server's; only the names are here, in one place, because two screens print
 * them: the Palette report table (components/super/studio/foundation/
 * PaletteReport.vue) and the live Brand card's save dialog, which lists the
 * pairs that fail (components/super/studio/live/LiveBrandCard.vue, W2 S9).
 *
 * No imports, so node can run it.
 */
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
