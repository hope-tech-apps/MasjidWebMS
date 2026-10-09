export type ClassSubjectTool = 'hifdh' | 'arabic_letters' | 'english_letters';

export type ClassSubject = {
    id: number;
    masjid_id: number;
    group_id: number;
    name: string;
    position: number;
    guide_subject: string | null;
    tool: ClassSubjectTool | null;
    hidden_at: string | null;
    created_at: string;
    updated_at: string;
};

/** Added only when the organisation's class subjects switch is on. */
export type ClassSubjectFields = {
    class_subjects_enabled?: boolean;
    class_subject_work_enabled?: boolean;
    class_subjects?: ClassSubject[];
    my_class_subject_ids?: number[] | null;
};

export type ClassSubjectListResponse = {
    status: 'success';
    data: ClassSubject[];
    meta: { guide_subjects: string[]; tools: ClassSubjectTool[] };
};
