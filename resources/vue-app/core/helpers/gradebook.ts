/**
 * The teacher's Grades tab, as plain functions (no Vue, no HTTP) so
 * `npm run test:spa` covers them: what the new-work form sends, what a weight
 * means for one piece of work, how the class's weights are typed and validated,
 * and how a child's average is worded.
 *
 * The rule under all of it: the server is the authority. These helpers shape a
 * request and word a payload; they never decide what counts, and they never turn
 * a levels mark into a percentage (that is exactly what the scale exists to stop).
 */

export interface WorkType { key: string; label: string }

/** What the new-work form holds. Strings, because that is what the inputs hold. */
export interface WorkForm {
    title: string;
    scale: string;
    points_possible: number | string;
    assigned_on: string;
    subject: string;
    type: string;
    /** A per-work override; '' means "inherit the class's weight for its type". */
    weight: string | number;
    /** The one standard, a snapshot of a pacing-guide row; null when none is chosen. */
    standard: { standard_code: string | null; curriculum_focus: string; curriculum_week_no: number | null } | null;
}

export interface FormContext {
    /** Subjects this teacher may file work under; empty means the school gave none to check against. */
    subjects: { name: string }[];
    weightingEnabled: boolean;
    standardsEnabled: boolean;
}

export function blankWorkForm(defaults: { scale: string; today: string; subject?: string | null }): WorkForm {
    return {
        title: '', scale: defaults.scale, points_possible: 10, assigned_on: defaults.today,
        subject: defaults.subject ?? '', type: '', weight: '', standard: null,
    };
}

/** The form for editing work that already exists, from its payload. */
export function workFormFrom(work: any): WorkForm {
    return {
        title: work.title ?? '',
        scale: work.scale ?? 'points',
        points_possible: work.points_possible ?? 10,
        assigned_on: work.assigned_on ?? '',
        subject: work.subject ?? '',
        type: work.type ?? '',
        weight: work.weight ?? '',
        standard: work.standard_code || work.curriculum_focus
            ? {
                standard_code: work.standard_code ?? null,
                curriculum_focus: work.curriculum_focus ?? '',
                curriculum_week_no: work.curriculum_week_no ?? null,
            }
            : null,
    };
}

/** True when the form may be sent: a title, and a subject wherever the school lists any. */
export function workFormReady(form: WorkForm, ctx: FormContext): boolean {
    if (!form.title.trim()) return false;
    if (ctx.subjects.length > 0 && !form.subject.trim()) return false;
    return true;
}

/**
 * The request body. Every optional field is sent EXPLICITLY, null to clear,
 * because on an edit an absent key means "leave it as it is" and an empty select
 * has to be able to say "none". Only points work has a maximum to send, since the
 * server forces it on the other scales. A weight is only sent for a weighted
 * class (the server refuses one otherwise), and the standard only where the
 * school teaches from a guide.
 */
export function workRequest(form: WorkForm, ctx: FormContext): Record<string, unknown> {
    const body: Record<string, unknown> = {
        title: form.title.trim(),
        scale: form.scale,
        assigned_on: form.assigned_on,
        subject: form.subject.trim() === '' ? null : form.subject.trim(),
        type: form.type === '' ? null : form.type,
    };

    if (form.scale === 'points') body.points_possible = Number(form.points_possible);

    if (ctx.weightingEnabled) {
        const w = String(form.weight).trim();
        body.weight = w === '' ? null : Number(w);
    }

    if (ctx.standardsEnabled) {
        body.standard_code = form.standard?.standard_code ?? null;
        body.curriculum_focus = form.standard?.curriculum_focus ?? null;
        body.curriculum_week_no = form.standard?.curriculum_week_no ?? null;
    }

    return body;
}

/** The first message the server sent for whichever field it refused, or the fallback. */
export function firstFieldError(e: any, fallback: string): string {
    const data = e?.response?.data?.data;
    if (data && typeof data === 'object') {
        for (const messages of Object.values(data)) {
            if (Array.isArray(messages) && typeof messages[0] === 'string') return messages[0];
        }
    }
    const message = e?.response?.data?.message;
    return typeof message === 'string' && message ? message : fallback;
}

// ---------------------------------------------------------------- weights

/**
 * How much one piece of work is worth: its own override, else its type's weight,
 * else nothing. The server reads the two differently: a type is ONE slot in the
 * average however many pieces are in it, and a piece with an override is a slot
 * of its own (App\Support\GradeRecord).
 */
export function effectiveWeight(work: { weight?: number | null; type?: string | null; scale?: string | null }, weights: Record<string, number>, enabled: boolean): number | null {
    if (!enabled) return null;
    // Excellent / Good / Needs work is never averaged, so nothing it carries (its type, or a weight typed
    // against it) is a weight: it is in no weighted figure (GradeRecord::weighted skips the scale).
    if (work.scale === SIMPLE_SCALE) return null;
    if (work.weight !== null && work.weight !== undefined) return work.weight;
    if (work.type && Object.prototype.hasOwnProperty.call(weights, work.type)) return weights[work.type];
    return null;
}

/**
 * Does a FAMILY see this child's weighted figures at all (review F8, 2026-09-29)? Only where the
 * class is weighted AND nothing the child was marked on is left out of them. Once a class sets
 * weights, older work with no type drops out of every weighted figure, so a child with nine older
 * pieces and one new quiz would read "100% across 1 piece" above a plain total of 60 of 90, and the
 * two would disagree with nothing to say why. Until the older work is typed, the family reads the
 * plain total and the per-type rows and no weighted figure (headline, weighted level or per-subject),
 * which needs no explanatory copy. Staff keep every figure and the untyped note that says what is left out.
 */
export function familySeesWeighted(weighting: { enabled?: boolean; untyped_excluded?: number | null } | null | undefined): boolean {
    return !!weighting?.enabled && !(Number(weighting?.untyped_excluded ?? 0) > 0);
}

/** The scale whose marks are the three words. Never averaged, so never weighted. */
export const SIMPLE_SCALE = 'simple';

/** What a piece of simple-scale work says where a weighted class would say "counts N". */
export const NOT_AVERAGED = 'not averaged';

/**
 * "counts 40", "counts 30 on its own", "not averaged" (simple-scale work in a weighted class), or "" when nothing is known.
 * A weight typed on one piece makes it a slot of its own beside its type's, not a share of the type's weight, so
 * it says "on its own": "(this work)" read as "30 of the type's 40".
 */
export function weightNote(work: { weight?: number | null; type?: string | null; scale?: string | null }, weights: Record<string, number>, enabled: boolean): string {
    if (enabled && work.scale === SIMPLE_SCALE) return NOT_AVERAGED;
    const w = effectiveWeight(work, weights, enabled);
    if (w === null) return '';
    return work.weight !== null && work.weight !== undefined ? `counts ${w} on its own` : `counts ${w}`;
}

/**
 * May this teacher change the class's weights? Only a teacher who is not limited to some
 * subjects (`group.my_subjects` is null or empty: every full-time teacher). The weights move
 * every subject's average, which families read, so a teacher limited to one subject reads them
 * and cannot change them. This only decides what the screen offers; the server refuses the same
 * request (review F5), which is the real boundary.
 */
export function mayChangeWeights(mySubjects: unknown): boolean {
    return !Array.isArray(mySubjects) || mySubjects.length === 0;
}

export function weightsFormFrom(weights: Record<string, number>, types: WorkType[]): Record<string, string> {
    const out: Record<string, string> = {};
    for (const t of types) out[t.key] = Object.prototype.hasOwnProperty.call(weights, t.key) ? String(weights[t.key]) : '';
    return out;
}

export type WeightsRequest = { ok: true; weights: Record<string, number> } | { ok: false; message: string };

/**
 * The class's weights from what was typed: ALL types, whole numbers from 0 to
 * `max`, not all zero. The server checks the same; this says it before a request.
 */
export function weightsRequest(form: Record<string, string>, types: WorkType[], max = 100): WeightsRequest {
    const weights: Record<string, number> = {};

    for (const t of types) {
        const raw = String(form[t.key] ?? '').trim();
        if (raw === '') return { ok: false, message: `Set a weight for ${t.label}, or clear the weights.` };
        if (!/^\d+$/.test(raw)) return { ok: false, message: `${t.label} needs a whole number from 0 to ${max}.` };
        const n = Number(raw);
        if (n > max) return { ok: false, message: `${t.label} needs a whole number from 0 to ${max}.` };
        weights[t.key] = n;
    }

    if (Object.values(weights).every((n) => n === 0)) {
        return { ok: false, message: 'At least one type of work has to count for something.' };
    }

    return { ok: true, weights };
}

// ---------------------------------------------------------------- figures

/** 81.4 -> "81.4%"; whole numbers lose the decimal; null is a dash. Never used for a levels mean. */
export function percentText(n: number | null | undefined): string {
    if (n === null || n === undefined || Number.isNaN(Number(n))) return '—';
    const v = Math.round(Number(n) * 10) / 10;
    return `${Number.isInteger(v) ? v.toFixed(0) : v.toFixed(1)}%`;
}

/**
 * A child's plain points as a percentage: "85%", "84.7%", or null when there is no denominator
 * (a percentage over an empty total is a division by zero on a child's record). THE one
 * place a points percentage is worked out in the browser, with `percentText`'s one rounding
 * (one decimal, whole numbers lose it), so two screens showing the same child's points
 * cannot say 85% and 84.7% beside each other (review F7). The server sends the plain figure only
 * as earned and possible; the weighted, per-type and per-subject percentages are its own.
 */
export function pointsPercentText(earned: number | string | null | undefined, possible: number | string | null | undefined): string | null {
    const out = Number(possible ?? 0);
    if (!out || Number.isNaN(out)) return null;

    return percentText((100 * Number(earned ?? 0)) / out);
}

/** "1 piece of work has no type" / "3 pieces of work have no type"; '' for none. */
export function untypedNote(n: number): string {
    if (!n || n < 1) return '';
    return n === 1 ? '1 piece of work has no type' : `${n} pieces of work have no type`;
}

export interface AverageLine { label: string; value: string; note: string }

/** Said under the figures of a teacher limited to some subjects: a parent's screen counts them all. */
export const FENCED_NOTE = 'These figures count only the subjects you teach in this class. A parent sees every subject, so their figures can differ.';

/** The note for a child's figures, or '' when they cover every subject. */
export function fencedNote(fenced: boolean | null | undefined): string {
    return fenced ? FENCED_NOTE : '';
}

/**
 * A child's headline figures, worded. The weighted average leads where the class
 * has weights and produced one; the plain pooled figure is always shown too, so
 * a teacher can see both and never wonders which one a parent is reading.
 * Levels are a MEAN LEVEL with its word and never a percentage.
 *
 * `fenced` is true for a teacher limited to some subjects: every label then says
 * so, because the figure is over their subjects only and the family's is not.
 */
export function averageLines(summary: any, fenced = false): AverageLine[] {
    const lines = averageLinesUnfenced(summary);
    return fenced ? lines.map((l) => ({ ...l, label: `${l.label} (your subjects)` })) : lines;
}

/** Said where a weighted class has no weighted figure for a child because the marks it has are simple-scale ones. */
export const NOT_AVERAGED_WHY = 'Excellent / Good / Needs work marks are never averaged, so they carry no weight and there is no weighted figure for them.';

function averageLinesUnfenced(summary: any): AverageLine[] {
    const lines: AverageLine[] = [];
    const w = summary?.weighting;

    // A weighted class with simple marks and nothing else to average: no weighted figure appears, and a
    // teacher who set weights and typed the work would otherwise wonder where it went.
    const noWeightedFigure = w?.enabled && (w.percent === null || w.percent === undefined) && (w.level_mean === null || w.level_mean === undefined);
    if (noWeightedFigure && Number(summary?.simple?.recorded ?? 0) > 0) {
        lines.push({ label: 'Weighted average', value: '—', note: NOT_AVERAGED_WHY });
    }

    if (w?.enabled && w.percent !== null && w.percent !== undefined) {
        lines.push({
            label: 'Weighted average',
            value: percentText(w.percent),
            note: `across ${w.points_pieces} piece${w.points_pieces === 1 ? '' : 's'} of work${w.untyped_excluded ? `; ${untypedNote(w.untyped_excluded)}, left out` : ''}`,
        });
    }

    if (summary?.points_counted > 0 && Number(summary.points_possible) > 0) {
        lines.push({
            label: w?.enabled ? 'Total points' : 'Points',
            value: `${summary.points_earned} of ${summary.points_possible}`,
            note: pointsPercentText(summary.points_earned, summary.points_possible) ?? '',
        });
    }

    const lv = summary?.levels;
    const wl = w?.enabled && w.level_mean !== null && w.level_mean !== undefined ? w : null;
    if (wl || (lv?.counted > 0 && lv.mean !== null)) {
        const mean = wl ? wl.level_mean : lv.mean;
        const word = wl ? wl.level_mean_label : lv.mean_label;
        lines.push({
            label: wl ? 'Weighted level' : 'Average level',
            value: String(mean),
            note: word ? String(word) : '',
        });
    }

    return lines;
}

/** A subject block's own line: its points and percentage, or its mean level. */
export function subjectLine(block: any): string {
    const parts: string[] = [];
    if (block?.points_counted > 0 && Number(block.points_possible) > 0) {
        parts.push(`${block.points_earned} of ${block.points_possible} (${percentText(block.percent)})`);
    }
    if (block?.weighted_percent !== null && block?.weighted_percent !== undefined) {
        parts.push(`weighted ${percentText(block.weighted_percent)}`);
    }
    if (block?.levels_counted > 0 && block.level_mean !== null) {
        parts.push(`level ${block.level_mean}${block.level_mean_label ? ` ${block.level_mean_label}` : ''}`);
    }
    return parts.join(' · ');
}

// ---------------------------------------------------------------- subjects

/** The folded key App\Support\SubjectKey gives a name: lower case, apostrophes dropped, spacing collapsed. */
function foldedSubject(name: string | null | undefined): string {
    return String(name ?? '').replace(/\s+/gu, ' ').trim().replace(/['\u2018\u2019\u02BB\u02BC\u02BE\u02BF]/g, '').toLowerCase();
}

/**
 * The school's weekly guide carries ONE column for "Qur'an & Islamic Studies".
 * It stays exactly as the school wrote it (splitting it would be authoring Islamic
 * content, RECON-PLAN section 6.1), so the plan picker lists it beside the three
 * separate subjects, labelled as what it is: the guide's column, not a subject.
 * It has to stay visible, because the standards search only works under it.
 */
export function isCombinedGuideColumn(name: string | null | undefined): boolean {
    const key = foldedSubject(name);
    return key === 'quran & islamic studies' || key === 'quran and islamic studies';
}
