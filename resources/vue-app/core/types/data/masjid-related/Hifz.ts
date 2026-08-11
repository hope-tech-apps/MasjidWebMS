/**
 * Hifz tracking — the halaqa's daily recitation record.
 *
 * TWO DOMAIN RULES ARE BAKED INTO THESE SHAPES:
 *
 * 1. ONLY SABAK ADVANCES A STUDENT. `sabqi` and `manzil` are revision; a manzil
 *    entry over al-Baqarah does not mean a child memorising juz 30 went
 *    backwards. Nothing that renders these may treat a revision entry as
 *    progress.
 * 2. PROGRESS IS A POSITION, NEVER A PERCENTAGE. Every figure is surah + ayah +
 *    juz. `memorized.total_ayahs` exists so a client can render "231 of 6236"
 *    without hardcoding the denominator — NOT so it can compute a percentage.
 *    "62% memorised" is a number no halaqa uses and no ijaza recognises.
 *
 * The record is private on exactly the same terms as a behaviour award, through
 * the same `GroupAudience` code, so there is no group-wide progress type here
 * and no "top memorisers" board. See .claude/rules/groups.md.
 */

import { GroupContact } from "./Group";

/** Mirrors `HifzEntry::KINDS` — the classical daily cycle, kept in its own words. */
export type HifzKind = 'sabak' | 'sabqi' | 'manzil';

/** Mirrors `HifzEntry::QUALITIES`. */
export type HifzQuality = 'excellent' | 'good' | 'fair' | 'repeat';

/** The student an entry concerns, as the membership edge that names them. */
export type HifzStudent = {
    membership_id: number;
    contact: GroupContact | null;
};

/**
 * One coordinate in the mushaf, as `QuranIndex::describe()` serves it — number,
 * name, ayah and derived juz, so a client never carries its own copy of the
 * mushaf to render "An-Naba 1-40, juz 30". Null when a stored range is corrupt.
 */
export type QuranPosition = {
    surah: number;
    surah_name: string;
    ayah: number;
    juz: number;
    ordinal: number;
};

export type HifzEntry = {
    id: number;
    group_id: number;
    student: HifzStudent | null;
    kind: HifzKind;
    from: QuranPosition | null;
    to: QuranPosition | null;
    ayahs: number;
    quality: HifzQuality;
    major_mistakes: number;
    minor_mistakes: number;
    note: string | null;
    recited_at: string | null;
    heard_by: { id: number; name: string } | null;
    /** `deleted_at` IS the correction clock — striking is the only edit there is. */
    corrected_at: string | null;
    created_at: string | null;
};

/** Shape submitted when recording a recitation. A closed interval that may cross surahs. */
export type HifzEntryPayload = {
    membership_id: number;
    kind: HifzKind;
    from_surah: number;
    from_ayah: number;
    to_surah: number;
    to_ayah: number;
    quality: HifzQuality;
    major_mistakes: number;
    minor_mistakes: number;
    note: string;
};

/**
 * Where the student is today, or the furthest they have reached. Both are
 * served because they are different facts: a child sent back over earlier
 * material is at that lesson today, and reporting the high-water mark would tell
 * a parent their child is somewhere they are not.
 */
export type HifzPosition = QuranPosition & {
    entry_id: number;
    recited_at: string | null;
    quality: HifzQuality;
    from: QuranPosition | null;
};

/** Coverage of one revision kind inside the reporting window. */
export type HifzRevisionCoverage = {
    entries: number;
    ayahs: number;
    juz: number[];
};

/**
 * A student's derived position and coverage. There is no stored `current_surah`
 * column anywhere — the position IS the sabak history, so striking a
 * mis-recorded lesson moves it back.
 */
export type HifzProgress = {
    student: HifzStudent;
    current_position: HifzPosition | null;
    furthest_position: HifzPosition | null;
    memorized: {
        ayahs: number;
        total_ayahs: number;
        juz_completed: number;
        juz_completed_list: number[];
        surahs_completed: number;
    };
    revision: {
        window_days: number;
        since: string;
        sabqi: HifzRevisionCoverage;
        manzil: HifzRevisionCoverage;
    };
    totals: {
        entries: number;
        by_kind: Record<HifzKind, number>;
        major_mistakes: number;
        minor_mistakes: number;
    };
};

/** `meta` on the hifz endpoints — the pickers render from the server's constants. */
export type HifzMeta = {
    group_label: string;
    kinds: HifzKind[];
    qualities: HifzQuality[];
    surah_count: number;
    total_ayahs: number;
    max_note_length: number;
    max_mistakes: number;
};
