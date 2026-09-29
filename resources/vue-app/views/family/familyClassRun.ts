import FamilyApiService, { rowsOf } from "@/core/services/FamilyApiService";

/**
 * The class screen's per-child fetch loops, as one run that belongs to the
 * school it began at.
 *
 * FamilyClass.vue derives `masjidId` and `base` from the shared route. When the
 * parent picks another school from "Your schools", FamilyLayout unmounts the
 * screen (its router-view is keyed by school), but a loop that is mid-way
 * through the children keeps running, and the route it reads now names the NEW
 * school: the next child's request would go to
 * `/api/family/masjids/<B>/groups/<whatever>/members/<A's membership id>/...`
 * under school B's bearer token, and a 403 there ("that is not your child")
 * would be taken for the end of B's session and drop B's slot from a screen
 * that no longer exists.
 *
 * So a run takes the class's base path ONCE, when it starts, and asks
 * `stale()` before every request it makes and before it reacts to any failure.
 * It is a plain module, free of the SFC and of the route, so `npm run test:spa`
 * can drive it.
 */

export interface ClassRun {
    /** `/api/family/masjids/<school>/groups/<class>`, fixed when the run began. */
    base: string;
    /** True once the screen is gone or the parent is at another school. */
    stale: () => boolean;
    /** The screen's session-expiry handler; answers true when it redirected. */
    fail: (e: any) => boolean;
}

export interface ClassRunSource {
    /** The school the route names right now. */
    masjidId: () => string;
    /** The class's API base for the route as it stands right now. */
    base: () => string;
    /** True once the owning screen has been unmounted. */
    unmounted: () => boolean;
    fail: (e: any) => boolean;
}

/**
 * Start a run: the school and the base path are read here, once. The run is
 * stale from the moment the route names another school, or the screen is gone,
 * whichever the route says afterwards.
 */
export function beginClassRun(source: ClassRunSource): ClassRun {
    const school = source.masjidId();
    const base = source.base();

    return {
        base,
        stale: () => source.unmounted() || source.masjidId() !== school,
        fail: source.fail,
    };
}

export interface ChildRecordSinks {
    alphabets: readonly string[];
    /** Only the tracks with work on them are kept (see trackHasWork in the view). */
    hasWork: (track: any) => boolean;
    setRecords: (membershipId: number, records: { awards: any[]; hifz: any[] }) => void;
    setLetters: (membershipId: number, tracks: any[]) => void;
    /** `null` means "we could not ask"; `[]` means there are none. */
    setArabicNotes: (membershipId: number, notes: any[] | null) => void;
}

export async function loadChildRecordsFor(run: ClassRun, children: any[], sinks: ChildRecordSinks): Promise<void> {
    for (const child of children) {
        if (run.stale()) return;

        try {
            const [awards, hifz] = await Promise.all([
                FamilyApiService.get(`${run.base}/members/${child.membership_id}/awards`),
                FamilyApiService.get(`${run.base}/members/${child.membership_id}/hifz`),
            ]);

            if (run.stale()) return;

            sinks.setRecords(child.membership_id, {
                awards: rowsOf(awards.data?.data),
                hifz: rowsOf(hifz.data?.data),
            });

            // Both alphabets, asked for separately because they ARE separate
            // records — same route, same ward-edge gate, one `?alphabet=` apart.
            // Each is caught on its own: a track that fails to load must not
            // take down the one that did, or a parent whose child has a full
            // qāʿidah page would be told nothing is recorded.
            const tracks = await Promise.all(sinks.alphabets.map(async (alphabet) => {
                try {
                    const l = await FamilyApiService.get(
                        `${run.base}/members/${child.membership_id}/letters?alphabet=${alphabet}`,
                    );
                    return l.data?.data ?? null;
                } catch {
                    // A class with no letter work is not an error; the card
                    // simply says nothing is recorded yet.
                    return null;
                }
            }));

            if (run.stale()) return;

            // Only the tracks with work on them. The payload is full whichever
            // way the class teaches, so this is where a school that does not
            // use a track stops being shown an empty one.
            sinks.setLetters(child.membership_id, tracks.filter((track) => track && sinks.hasWork(track)));

            // The teacher's daily Arabic notes. Its own try: a failure here must
            // not blank the letters above, and it must not read as "no notes"
            // either — that is a sentence about the child that we would be
            // inventing.
            try {
                const n = await FamilyApiService.get(
                    `${run.base}/members/${child.membership_id}/arabic-notes`,
                );
                if (run.stale()) return;
                sinks.setArabicNotes(child.membership_id, rowsOf(n.data?.data));
            } catch (e) {
                if (run.stale()) return;
                if (run.fail(e)) return;
                sinks.setArabicNotes(child.membership_id, null);
            }
        } catch (e) {
            if (run.stale()) return;
            if (run.fail(e)) return;
            sinks.setRecords(child.membership_id, { awards: [], hifz: [] });
        }
    }
}

export interface ReportCardSinks {
    setCards: (membershipId: number, rows: any[]) => void;
    /** One sibling's list failed; the others are still shown. */
    markPartial: () => void;
}

/** True when every child was asked about; false when the run stopped early. */
export async function loadReportCardsFor(run: ClassRun, children: any[], sinks: ReportCardSinks): Promise<boolean> {
    for (const child of children) {
        if (run.stale()) return false;

        try {
            const res = await FamilyApiService.get(
                `${run.base}/members/${child.membership_id}/report-cards`,
            );
            if (run.stale()) return false;
            sinks.setCards(child.membership_id, rowsOf(res.data?.data));
        } catch (e) {
            if (run.stale()) return false;
            if (run.fail(e)) return false;
            // Per child, so one sibling's failure does not blank the other's
            // reports — the same reason the records loop catches inside its
            // loop rather than around it.
            sinks.setCards(child.membership_id, []);
            sinks.markPartial();
        }
    }

    return true;
}

export interface GradeSinks {
    setMarks: (membershipId: number, marks: any) => void;
    /** The sibling `performance_levels` of a response that carried one. */
    setLevels: (levels: any[]) => void;
    /** What a child shows when the request failed. */
    empty: any;
    markError: () => void;
}

/** True when every child was asked about; false when the run stopped early. */
export async function loadGradesFor(run: ClassRun, children: any[], sinks: GradeSinks): Promise<boolean> {
    for (const child of children) {
        if (run.stale()) return false;

        try {
            const res = await FamilyApiService.get(
                `${run.base}/members/${child.membership_id}/grades`,
            );
            if (run.stale()) return false;
            sinks.setMarks(child.membership_id, res.data?.data ?? sinks.empty);
            // `performance_levels` is a SIBLING of `data`, not a member of it.
            if (res.data?.performance_levels) sinks.setLevels(res.data.performance_levels);
        } catch (e) {
            if (run.stale()) return false;
            if (run.fail(e)) return false;
            // Caught INSIDE the loop: one sibling's failure must not blank the
            // other's marks.
            sinks.setMarks(child.membership_id, sinks.empty);
            sinks.markError();
        }
    }

    return true;
}
