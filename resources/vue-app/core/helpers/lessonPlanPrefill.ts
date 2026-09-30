/**
 * What a pacing-guide week writes into a lesson plan, as plain functions (no
 * Vue, no HTTP), so `node --test` can prove them: the plan form is a component
 * and the test runner cannot mount one.
 *
 * Two rules live here.
 *
 * ISLAMIC INTEGRATION. A plan's "Islamic integration" box is written from the
 * same week's OTHER subjects. It used to take the first sibling whose subject
 * matches /Qur|Islamic/, because the guide had one combined "Qur'an & Islamic
 * Studies" column. The school's separated plan (Pre-K to Grade 2, Quarter 1) has
 * Qur'an and Islamic Studies as two subjects, so:
 *   - exactly one Islamic sibling: its focus alone, exactly as before;
 *   - several: one `Subject: focus` line each, so neither is dropped;
 *   - Arabic Language stays with the other subjects (it is not Islamic Studies).
 *
 * LEARNING OUTCOME. The school's plan gives each week a Learning Outcome beside
 * its Objective. It fills the plan's outcomes only where the teacher has written
 * none or the guide wrote the only one there, which is the rule `autoFill` in
 * TeacherClass.vue applies to every other field: what a teacher wrote or edited
 * is theirs and is never touched.
 */

export interface GuideSibling {
    subject: string;
    focus: string;
}

/** The test the form has always used for "this subject is Islamic". */
const ISLAMIC = /Qur|Islamic/i;

export function islamicIntegration(siblings: GuideSibling[]): { islamic: string; others: string } {
    const hits = siblings.filter((s) => ISLAMIC.test(s.subject));
    const islamic = hits.length === 1
        ? hits[0].focus
        : hits.map((s) => `${s.subject}: ${s.focus}`).join('\n');
    const others = siblings.filter((s) => !hits.includes(s))
        .map((s) => `${s.subject}: ${s.focus}`).join('\n');
    return { islamic, others };
}

/**
 * The outcomes list a guide week's Learning Outcome should write, or `null` to
 * leave the field alone.
 *
 * `current` is what the form holds. `lastAuto` is the outcome the guide last
 * wrote there, if it did. It writes `[outcome]` when `current` has no non-blank
 * entry, or is exactly `[lastAuto]`; anything else is the teacher's. `null` too
 * when the guide has no outcome, and when the list already is `[outcome]`.
 */
export function outcomeFill(
    current: string[] | null | undefined,
    outcome: string | null | undefined,
    lastAuto: string | null | undefined,
): string[] | null {
    if (outcome == null || outcome === '') return null;
    const held = (current ?? []).map((o) => String(o ?? '').trim()).filter((o) => o !== '');
    const untouched = held.length === 0
        || (held.length === 1 && lastAuto != null && held[0] === String(lastAuto).trim());
    if (!untouched) return null;
    if (held.length === 1 && held[0] === outcome) return null;
    return [outcome];
}
