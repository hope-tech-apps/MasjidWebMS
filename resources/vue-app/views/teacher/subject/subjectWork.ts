/** Shared subject-page contract; IDs identify class memberships, never contacts. */
export type WorkStudent = { id: number; name: string; grade_label: string; grade_key: string };
export type WorkLevel = { level: number; short_label: string; description: string };
export type WorkMark = { group_membership_id: number; level: number | null; comment: string | null; updated_at: string | null; shared_with_family?: boolean };
export type WorkPiece = {
    source: 'guide' | 'plan' | 'own'; piece_id: number | null; title: string; detail: string | null;
    marks: WorkMark[]; mark_count: number; grade_label?: string; week_no?: number; guide_subject?: string;
    standard_code?: string | null; lesson_plan_id?: number | null;
    wording_changed?: boolean; marked_against_date?: string; moved_to?: string | null; moved_elsewhere?: boolean; no_longer_followed?: boolean;
};
export type WorkBlock = { grade_key: string; grade_label: string; students: WorkStudent[]; entries: WorkPiece[]; opening_week_no: number; selected_week_no: number; opening_guide_subject?: string; selected_guide_subject?: string };
export type WorkNote = { id: number; group_membership_id: number | null; student_name: string; body: string; author_name: string; created_at: string; shared_with_family?: boolean };
export type WorkPage = { sharing_enabled?: boolean; curriculum_empty_message?: string; levels: WorkLevel[]; students: WorkStudent[]; curriculum: WorkBlock[]; lesson_plans: WorkPiece[]; own_pieces: WorkPiece[]; notes: WorkNote[] };
export type WorkApi = { get(url: string): Promise<any>; post(url: string, body: unknown): Promise<any>; put(url: string, body: unknown): Promise<any>; delete(url: string, body?: unknown): Promise<any> };
/** Reads general failures from the application data envelope or a Laravel errors bag. */
export function workError(error: any, fallback: string): string {
    const body = error?.response?.data;
    const bag = body?.errors ?? (typeof body?.data === 'object' ? body.data : null);
    if (bag) return Object.values(bag).flat().join(' ');
    return body?.message || (typeof body?.data === 'string' ? body.data : '') || (error?.response ? fallback : `${fallback} Check your connection and try again.`);
}

/** Separates known field errors from general failures, retaining messages without a control. */
export function workFieldErrors(error: any, known: string[]): { fields: Record<string, string>; general: string } {
    const body = error?.response?.data;
    const bag = body?.errors ?? (error?.response?.status === 422 && typeof body?.data === 'object' ? body.data : null);
    if (!bag) return { fields: {}, general: workError(error, 'This change could not be saved.') };
    const fields: Record<string, string> = {}; const general: string[] = [];
    for (const [key, messages] of Object.entries(bag)) {
        const words = [messages].flat().join(' ');
        if (known.includes(key)) fields[key] = words; else general.push(words);
    }
    return { fields, general: general.join(' ') };
}

/** A lesson plan's piece is named "2026-10-09: objective" by the server (it sorts on that). People read "Oct 9, 2026 · objective". */
export function pieceTitle(piece: Pick<WorkPiece, 'source' | 'title'>): string {
    const match = piece.source === 'plan' ? /^(\d{4})-(\d{2})-(\d{2}): ?(.*)$/s.exec(piece.title) : null;
    if (!match) return piece.title;
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return `${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} · ${match[4]}`;
}

/** "Oct 9, 2026, 4:29 PM": the form every other teacher screen writes a moment in. */
export function workMoment(value: string): string {
    return new Date(value).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

/** The first words of what a teacher typed, to name its Edit and Delete buttons for a screen reader. */
export function firstWords(text: string, words = 6): string {
    const parts = text.trim().split(/\s+/);
    return parts.slice(0, words).join(' ') + (parts.length > words ? '…' : '');
}
