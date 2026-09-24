/**
 * The ARIA tabs pattern's keys (WAI-ARIA Authoring Practices, "Tabs"): Left and
 * Right move to the previous and next tab, wrapping at the ends, and Home and
 * End go to the first and last. First used by Studio's preview tabs
 * (components/super/studio/preview/StudioPreviewPanel.vue).
 *
 * No imports, so tests/tab-keys.test.ts runs it under node.
 */

/** The index of the tab `key` moves to from `current`, or null for a key the pattern leaves alone. */
export function tabIndexForKey(key: string, current: number, count: number): number | null {
    if (count < 1) return null;

    switch (key) {
        case 'ArrowRight':
            return (current + 1) % count;
        case 'ArrowLeft':
            return (current - 1 + count) % count;
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
}
