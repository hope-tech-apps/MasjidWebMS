/**
 * The runs of tiles a letter tracker draws (T-004.2).
 *
 * Arabic is ONE run of 28 tiles, one per letter. English is TWO runs of 26,
 * Capitals then Lower case, one tile per DRILL (`a.upper`, `a.lower`), each run
 * with its own count beside the overall "of 52". The server says which it is:
 * a tracker payload with a non-empty `sets` list is split by case, anything else
 * is a single run exactly as before, so a client never branches on the alphabet
 * id and a third alphabet with sets would need no change here.
 *
 * Plain functions with no Vue in them so `npm run test:spa` covers the teacher,
 * office and family screens with one set of tests.
 */

export interface LetterTile {
    /** Unique within a run; the drill id for a split track, the letter id otherwise. */
    key: string;
    /** The letter this tile opens (the letter card lists its drills). */
    letterId: string;
    /** The drill the tile stands for, or null when the tile is a whole letter. */
    drillId: string | null;
    text: string;
    name: string;
    status: string;
    /** What the family portal's tooltip calls it: "Capital A" or the letter's transliteration. */
    title: string;
}

export interface LetterRun {
    /** The set id (`upper`, `lower`), or `all` for an unsplit track. */
    id: string;
    /** The server's English name for the set; null for an unsplit track (no heading). */
    label: string | null;
    /** This run's own count; null for an unsplit track, whose count is the overall one. */
    mastered: number | null;
    total: number | null;
    tiles: LetterTile[];
}

export function letterRuns(tracker: any): LetterRun[] {
    const letters: any[] = tracker?.letters ?? [];
    const sets: any[] = tracker?.sets ?? [];

    if (!sets.length) {
        return [{
            id: 'all',
            label: null,
            mastered: null,
            total: null,
            tiles: letters.map((l) => ({
                key: String(l.id),
                letterId: String(l.id),
                drillId: null,
                text: l.glyph,
                name: l.transliteration ?? l.glyph,
                status: l.status,
                title: l.transliteration || l.glyph,
            })),
        }];
    }

    const totals: any[] = tracker?.set_totals ?? [];

    return sets.map((set) => {
        const own = totals.find((t) => t.id === set.id);
        const tiles: LetterTile[] = [];

        for (const l of letters) {
            for (const d of l.drills ?? []) {
                if (d.set !== set.id) continue;
                tiles.push({
                    key: String(d.id),
                    letterId: String(l.id),
                    drillId: String(d.id),
                    text: d.text,
                    name: l.transliteration ?? d.label,
                    status: d.status,
                    title: d.label,
                });
            }
        }

        return {
            id: String(set.id),
            label: set.label ?? null,
            mastered: own ? Number(own.mastered) : tiles.filter((t) => t.status === 'mastered').length,
            total: own ? Number(own.total) : tiles.length,
            tiles,
        };
    });
}

/**
 * The tile a tap on `tile` leaves open, given the one open now (T-004.2 follow-up).
 *
 * The open card is tracked by TILE key, not by letter id: the capital and the
 * lower-case tile of one letter share a letter id, so toggling on the letter id
 * closed the card when a teacher tapped the other case of the letter she was
 * already looking at. Tapping the open tile again still closes it.
 */
export function toggledTileKey(open: string | null, tile: Pick<LetterTile, 'key'>): string | null {
    return open === tile.key ? null : tile.key;
}

/** The letter the open tile belongs to (its card lists both cases), or null when nothing is open. */
export function letterIdOfTile(runs: readonly LetterRun[], key: string | null): string | null {
    if (key === null) return null;

    for (const run of runs) {
        const tile = run.tiles.find((t) => t.key === key);
        if (tile) return tile.letterId;
    }

    return null;
}

/**
 * The words that sit beside a drill in a notes list. A drill that belongs to a
 * set (English `a.upper`) is captioned with the portal's OWN name for the set,
 * asked through `setName`; the server's `label` ("Capital A") is English text and
 * would appear untranslated in every other portal language. A drill with no set
 * (Arabic) keeps its label, which is the letter's own name.
 */
export function drillCaption(
    drill: { set?: string | null; label?: string | null },
    setName: (setId: string) => string,
): string {
    return drill.set ? setName(String(drill.set)) : String(drill.label ?? '');
}
