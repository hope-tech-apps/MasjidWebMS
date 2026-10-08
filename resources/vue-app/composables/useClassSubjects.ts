import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { AxiosInstance, AxiosResponse } from 'axios';
import { apiErrorText } from '@/core/services/ApiErrors';
import type { ClassSubject, ClassSubjectFields, ClassSubjectListResponse, ClassSubjectTool } from '@/core/types/data/masjid-related/ClassSubject';

/** An address selects a class line or an immutable subject ID, never a tool name. */
export type ClassChoice = { key: string; label: string; query: { tab?: string; subject?: string } };
/** Ordered heading and its lines, shared by the two class menus. */
export type ClassSection = { label: string; items: ClassChoice[] };
type ClassPayload = ClassSubjectFields & { class_store?: boolean };
/** Tool bindings keep the legacy panels, state and loaders in their owning view. */
export const subjectTool = (tool: ClassSubjectTool | null) => tool === 'hifdh'
    ? { tab: 'hifz', alphabet: null }
    : tool === 'arabic_letters' || tool === 'english_letters'
        ? { tab: 'letters', alphabet: tool === 'english_letters' ? 'english' : 'arabic' }
        : { tab: 'subject', alphabet: null };

type SubjectReader = { get(url: any): Promise<AxiosResponse> };
type OfficeSubjectTransport = SubjectReader & {
    delete(url: any): Promise<AxiosResponse>;
    VueApp: { axios: Pick<AxiosInstance, 'post' | 'put'> };
};
const readSubject = async (api: SubjectReader, base: string, id: number): Promise<ClassSubject> =>
    (await api.get(`${base}/subjects/${id}`)).data.data;

/** All new wire reads and writes live here; office JSON preserves explicit nulls. */
export function classSubjectApi(api: OfficeSubjectTransport, base: string) {
    const read = (url: string) => api.get(url);
    const json = (method: 'post' | 'put', url: string, data: unknown) =>
        api.VueApp.axios[method](url, data, { headers: { 'Content-Type': 'application/json' } });
    return {
        list: async (): Promise<ClassSubjectListResponse> => (await read(`${base}/subjects`)).data,
        detail: (id: number): Promise<ClassSubject> => readSubject(api, base, id),
        schoolNames: async (): Promise<string[]> => {
            const schoolBase = base.slice(0, base.lastIndexOf('/groups/'));
            const res = await read(`${schoolBase}/school-subjects`);
            return (res.data?.data ?? []).map((s: { name: string }) => s.name);
        },
        save: (id: number | null, data: { name: string; guide_subject?: string | null; tool?: ClassSubjectTool | null; attach_saved_work?: boolean }) =>
            json(id === null ? 'post' : 'put', `${base}/subjects${id === null ? '' : `/${id}`}`, data),
        reorder: (ids: number[]) => json('put', `${base}/subjects/reorder`, { subject_ids: ids }),
        hide: (id: number) => api.delete(`${base}/subjects/${id}`),
        restore: (id: number) => json('put', `${base}/subjects/${id}/restore`, {}),
        addCurrentGrades: () => json('post', `${base}/subjects/add-for-current-grades`, {}),
    };
}

const line = (tab: string, label: string): ClassChoice => ({ key: `tab:${tab}`, label, query: { tab } });
/** Screen navigation is separate from authorization; every subject also passes its detail GET. */
export function useClassSubjects<T extends string>(options: {
    realm: 'teacher' | 'office'; group: Ref<ClassPayload | null>; base: Ref<string>; api: SubjectReader;
    activeTab: Ref<T>; activate: (tab: string, alphabet: string | null) => void;
}) {
    const route = useRoute();
    const router = useRouter();
    const enabled = computed(() => options.group.value?.class_subjects_enabled === true);
    const visibleSubjects = computed(() => {
        const group = options.group.value;
        const mine = group?.my_class_subject_ids;
        return (group?.class_subjects ?? []).filter(s => !s.hidden_at &&
            (options.realm === 'office' || !mine?.length || mine.includes(s.id)))
            .slice().sort((a, b) => a.position - b.position || a.id - b.id);
    });
    const sections = computed<ClassSection[]>(() => [
        { label: 'Class', items: [line('roster', 'Roster'), ...(options.realm === 'teacher' ? [line('attendance', 'Attendance')] : []), line('points', 'Points'),
            ...(options.realm === 'teacher' && options.group.value?.class_store === true ? [line('store', 'Class Store')] : [])] },
        { label: 'Subjects', items: visibleSubjects.value.map(s => ({ key: `subject:${s.id}`, label: s.name, query: { subject: String(s.id) } })) },
        { label: 'Families', items: [line('story', 'Class Story'), line(options.realm === 'teacher' ? 'messages' : 'threads', 'Messages')] },
        { label: 'Planning and marks', items: [line('lessons', 'Lesson Plans'), line('grades', 'Grades'),
            ...(options.realm === 'teacher' ? [line('reports', 'Reports')] : []), line('files', 'Files')] },
    ]);
    const subject = ref<ClassSubject | null>(null);
    const notice = ref('');
    const busy = ref(false);
    const currentKey = computed(() => subject.value ? `subject:${subject.value.id}` : `tab:${options.activeTab.value}`);
    const title = computed(() => subject.value?.name ?? sections.value.flatMap(s => s.items).find(s => s.key === currentKey.value)?.label ?? 'Roster');
    const fixedAlphabet = computed(() => subject.value ? subjectTool(subject.value.tool).alphabet : null);
    let generation = 0;
    let alive = true;
    let legacyTab = options.activeTab.value;
    let savedQuery: typeof route.query | null = null;

    const activate = (tab: string, alphabet: string | null = null) => {
        options.activate(tab, alphabet);
    };
    const fallback = async (message: string) => {
        subject.value = null;
        notice.value = message;
        activate('roster');
        const query = { ...route.query }; delete query.subject; query.tab = 'roster'; delete query.week;
        await router.replace({ query });
        if (alive && enabled.value && route.query.tab === 'roster' && route.query.subject === undefined) notice.value = message;
    };
    const applyQuery = async () => {
        const token = ++generation;
        if (!enabled.value || !alive) return;
        busy.value = false;
        notice.value = '';
        const raw = route.query.subject;
        if (raw !== undefined) {
            subject.value = null;
            // Unmount the previous disclosure until the server validates the next one.
            activate('subject');
            busy.value = true;
            try {
                if (typeof raw !== 'string' || !/^[1-9]\d*$/.test(raw) || !Number.isSafeInteger(Number(raw))) throw new Error('Invalid subject');
                const result = await readSubject(options.api, options.base.value, Number(raw));
                if (!alive || token !== generation || !enabled.value) return;
                if (result.id !== Number(raw) || !visibleSubjects.value.some(s => s.id === result.id)) throw new Error('Unavailable subject');
                subject.value = result;
                const tool = subjectTool(result.tool);
                activate(tool.tab, tool.alphabet);
            } catch (error) {
                if (!alive || token !== generation || !enabled.value) return;
                const status = (error as any)?.response?.status;
                if (status && status !== 403 && status !== 404) {
                    await fallback(apiErrorText(error, 'That subject could not be loaded. Showing Roster.'));
                } else {
                    await fallback('That subject is not available. Showing Roster.');
                }
            } finally {
                if (alive && token === generation) busy.value = false;
            }
            return;
        }
        subject.value = null;
        const tab = route.query.tab;
        const choice = sections.value.flatMap(s => s.items).find(s => s.query.tab === tab);
        activate(choice?.query.tab ?? 'roster');
    };
    watch(enabled, (on) => {
        ++generation;
        if (on) {
            legacyTab = options.activeTab.value;
            const restore = savedQuery; savedQuery = null;
            if (restore && JSON.stringify(restore) !== JSON.stringify(route.query)) router.replace({ query: restore });
            else applyQuery();
        } else {
            savedQuery = { ...route.query };
            subject.value = null;
            busy.value = false;
            notice.value = '';
            activate(legacyTab);
        }
    });
    watch(() => route.query, () => { if (enabled.value) applyQuery(); }, { deep: true });
    // Links inside legacy panels (for example “Message their family”) still select a line.
    watch(options.activeTab, (tab) => {
        if (!enabled.value || busy.value) return;
        if (subject.value && subjectTool(subject.value.tool).tab === tab) return;
        if (route.query.subject === undefined && (route.query.tab ?? 'roster') === tab) return;
        if (sections.value.some(s => s.items.some(item => item.query.tab === tab))) choose(line(tab, ''));
    });
    const choose = (choice: ClassChoice) => {
        const query = { ...route.query }; delete query.tab; delete query.subject;
        // A week belongs to Points, never to another line's address.
        if (choice.query.tab !== 'points') delete query.week;
        Object.assign(query, choice.query);
        if (JSON.stringify(query) === JSON.stringify(route.query)) return;
        router.push({ query });
    };
    const href = (choice: ClassChoice) => {
        const query = { ...route.query }; delete query.tab; delete query.subject;
        if (choice.query.tab !== 'points') delete query.week;
        return router.resolve({ query: { ...query, ...choice.query } }).href;
    };
    watch(visibleSubjects, () => {
        if (enabled.value && subject.value) {
            const current = visibleSubjects.value.find(s => s.id === subject.value?.id);
            if (!current) fallback('That subject is not available. Showing Roster.');
            else subject.value = current;
        }
    });
    // Legacy requests keep their OFF behavior; ON reads cannot cross a navigation lifetime.
    const keepRead = (identity: () => unknown = () => null) => {
        const token = generation;
        const wasEnabled = enabled.value;
        const expected = identity();
        return () => alive && (!wasEnabled && !enabled.value ||
            wasEnabled === enabled.value && token === generation && identity() === expected);
    };
    onBeforeUnmount(() => { alive = false; ++generation; });
    return { enabled, sections, subject, notice, busy, currentKey, title, fixedAlphabet, choose, href, keepRead };
}
