import { test } from 'node:test';
import assert from 'node:assert/strict';
import { modulesFor } from './support/batch3Modules.ts';
import { mountSfc, flush, click, httpError, withDocumentKeys } from './support/mountSfc.ts';

async function teacherScreen() {
    withDocumentKeys(); (globalThis as any).document.body = { style: {} };
    (globalThis as any).window ??= { addEventListener() {}, removeEventListener() {} };
    const rows = [{ membership_id: 1, status: 'excused', note: 'Medical appointment', contact: { first_name: 'Practice', last_name: 'Student' } }, { membership_id: 2, status: 'present', note: 'Earlier note', contact: { first_name: 'Other', last_name: 'Student' } }];
    let revision = 'revision-one'; let refusal: any = null;
    const writes: any[] = []; const reads: string[] = [];
    const response = () => ({ headers: { 'x-attendance-revision': revision }, data: { data: { session_date: '2026-10-19', taken: true, students: structuredClone(rows) } } });
    const api = {
        get: async (url: string) => { reads.push(url); return url.includes('/attendance?') ? response() : { data: { data: url.endsWith('/groups/1') ? { id: 1, name: 'Practice Class', students: rows } : [] } }; },
        put: async (url: string, body: any, headers?: any) => { writes.push({ url, body, headers }); if (refusal) throw refusal; for (const mark of body.marks) Object.assign(rows.find(s => s.membership_id === mark.membership_id)!, { status: mark.status, note: mark.note ?? null }); return response(); },
    };
    const modules = await modulesFor('views/teacher/TeacherClass.vue', {
        'vue-router': { useRoute: () => ({ params: { groupId: '1' }, query: {} }), useRouter: () => ({ replace() {}, push() {} }) },
        '@/core/services/TeacherApiService': { default: api, rowsOf: (data: any) => Array.isArray(data) ? data : data?.data ?? [] },
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
    });
    const s = await mountSfc('views/teacher/TeacherClass.vue', {}, modules); await flush(12);
    click(s.button('Attendance')); await flush(12);
    return { ...s, writes, reads, rows, fail: (e: any) => { refusal = e; }, server: (id: number, change: any) => { Object.assign(rows[id - 1], change); revision = 'revision-two'; } };
}
function status(s: any, row: number, label: string) {
    const groups = s.all((n: any) => n.props.role === 'group' && String(n.props['aria-label']).startsWith('Mark '));
    click(groups[row].children.find((n: any) => n.tag === 'button' && n.props.title === label));
}

test('teacher status save round-trips the office note unchanged without a note editor', async () => {
    const s = await teacherScreen(); try {
        status(s, 0, 'Late'); click(s.button('Save register')); await flush(12);
        assert.deepEqual(s.writes[0].body.marks, [{ membership_id: 1, status: 'late', note: 'Medical appointment' }, { membership_id: 2, status: 'present', note: 'Earlier note' }]);
        assert.equal(s.rows[0].note, 'Medical appointment');
        assert.equal(s.writes[0].headers?.['X-Attendance-Revision'], 'revision-one');
        assert.equal(s.all(n => n.tag === 'textarea').length, 0);
    } finally { s.unmount(); }
});

test('teacher conflict reloads current notes and untouched marks while keeping changed statuses visible', async () => {
    const s = await teacherScreen(); try {
        status(s, 1, 'Absent');
        s.server(1, { status: 'late', note: 'Office correction' });
        s.server(2, { status: 'excused', note: 'New office note' });
        s.fail(httpError(409, { message: 'This register was changed by Office: Practice Office at noon. Reload it, then make your change.' }));
        click(s.button('Save register')); await flush(12);
        assert.equal(s.reads.filter(url => url.includes('/attendance?')).length, 2);
        assert.match(s.text(), /This register was changed by Office: Practice Office/);
        assert.match(s.text(), /Your unsaved/);
        s.fail(null); click(s.button('Save register')); await flush(12);
        assert.equal(s.writes[1].headers?.['X-Attendance-Revision'], 'revision-two');
        assert.deepEqual(s.writes[1].body.marks, [{ membership_id: 1, status: 'late', note: 'Office correction' }, { membership_id: 2, status: 'absent', note: 'New office note' }]);
    } finally { s.unmount(); }
});

test('both attendance transports forward the revision as a request header without changing the JSON body', async () => {
    const { loadTs } = await import('./support/mountSfc.ts');
    (globalThis as any).window.location = { href: 'https://practice.invalid/class' };
    const { default: axios } = await import('axios');
    const common = { '@/core/tenancy/tenantRequests': { stampTenantEpoch: (c: any) => c, isFromSupersededEpoch: () => false, recordServerTenant: () => {} }, axios: { default: axios }, '../types/config/BackendApiRoutes': {} };
    const teacher = await loadTs('core/services/TeacherApiService.ts', {
        ...common, '@/core/constants/appConfigConstants': { API_CONFIG: {}, LOCAL_STORAGE_KEYS: {} },
        '@/core/tenancy/teacherSchoolGuard': {}, '@/core/tenancy/teacherSchoolGuardCore': {},
    });
    const office = await loadTs('core/services/ApiService.ts', { ...common, vue: {}, 'vue-axios': { default: {} } });
    const requests: any[] = [];
    const client = axios.create({ adapter: async (config: any) => { requests.push(config); return { status: 200, data: {}, headers: {}, statusText: 'OK', config }; } });
    teacher.default.client = client;
    const body = { session_date: '2026-10-19', marks: [{ membership_id: 1, status: 'late', note: 'Medical appointment' }] };
    await teacher.default.put('/teacher/attendance', body, { 'X-Attendance-Revision': 'teacher-revision' });
    office.default.init({ axios: client, use() {} }, 'https://practice.invalid', false);
    await office.default.put('/office/attendance', body, 'url', { 'X-Attendance-Revision': 'office-revision' });
    assert.equal(requests[0].headers.get('X-Attendance-Revision'), 'teacher-revision');
    assert.equal(requests[1].headers.get('X-Attendance-Revision'), 'office-revision');
    assert.deepEqual(JSON.parse(requests[0].data), body);
    assert.equal(requests[0].headers.get('Content-Type'), 'application/json');
    assert.equal(requests[1].headers.get('Content-Type'), 'application/json');
    assert.deepEqual(JSON.parse(requests[1].data), body);
});
