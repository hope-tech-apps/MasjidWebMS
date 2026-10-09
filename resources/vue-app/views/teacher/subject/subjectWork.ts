/** Shared subject-page contract; IDs identify class memberships, never contacts. */
export type WorkStudent = { id: number; name: string; grade_label: string; grade_key: string };
export type WorkLevel = { level: number; short_label: string; description: string };
export type WorkMark = { group_membership_id: number; level: number | null; comment: string | null; updated_at: string | null };
export type WorkPiece = {
    source: 'guide' | 'plan' | 'own'; piece_id: number | null; title: string; detail: string | null;
    marks: WorkMark[]; mark_count: number; grade_label?: string; week_no?: number; guide_subject?: string;
    standard_code?: string | null; lesson_plan_id?: number | null;
    wording_changed?: boolean; marked_against_date?: string;
};
export type WorkBlock = { grade_key: string; grade_label: string; students: WorkStudent[]; entries: WorkPiece[]; opening_week_no: number; selected_week_no: number; opening_guide_subject?: string; selected_guide_subject?: string };
export type WorkNote = { id: number; group_membership_id: number | null; student_name: string; body: string; author_name: string; created_at: string };
export type WorkPage = { curriculum_empty_message?: string; levels: WorkLevel[]; students: WorkStudent[]; curriculum: WorkBlock[]; lesson_plans: WorkPiece[]; own_pieces: WorkPiece[]; notes: WorkNote[] };
export type WorkApi = { get(url: string): Promise<any>; post(url: string, body: unknown): Promise<any>; put(url: string, body: unknown): Promise<any>; delete(url: string, body?: unknown): Promise<any> };
/** Reads general failures from the application data envelope or a Laravel errors bag. */
export function workError(error: any, fallback: string): string {
    const body = error?.response?.data;
    const bag = body?.errors ?? (typeof body?.data === 'object' ? body.data : null);
    if (bag) return Object.values(bag).flat().join(' ');
    return body?.message || (typeof body?.data === 'string' ? body.data : '') || (error?.response ? fallback : `${fallback} Check your connection and try again. ${error?.message ?? ''}`);
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
