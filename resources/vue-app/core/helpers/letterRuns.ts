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
