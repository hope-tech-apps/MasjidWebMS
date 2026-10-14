import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mountSfc, click, chooseOption, flush, deferred } from './support/mountSfc.ts';
import * as errors from '../core/services/ApiErrors.ts';
const vue = createRequire(import.meta.url)('vue');
const fields = ['class', 'student_first_name', 'student_last_name', 'grade', 'guardian_first_name', 'guardian_last_name', 'guardian_email', 'guardian_phone', 'guardian_2_first_name', 'guardian_2_last_name', 'guardian_2_email', 'guardian_2_phone'];
const mapping = Object.fromEntries(fields.map((f, i) => [f, i < 2 ? i : null]));
const inspection = { headers: ['Homeroom', 'Given Name', 'Other'], samples: [['Room A', 'Example', 'Value 1'], ['Room B', 'Next', 'Value 2'], ['Room C', 'Third', 'Value 3']], mapping, required: ['class', 'student_first_name'], remembered: false };
const preview = { rows_read: 1, totals: { students: { in_file: 1, existing: 0, to_create: 1 }, guardians: { in_file: 0, existing: 0, to_create: 0 }, edges: 0, refused: 0 }, students: [{ line: 2, lines: [2], class: 'Room A', name: 'Example', grade: null, existing: false }], guardians: [], refused: [], can_commit: true, receipt: 'receipt', expires_in_minutes: 30 };
async function screen(api: any) {
    return mountSfc('views/dashboard/RosterImportView.vue', {}, { vue,
        '@/components/PageDataContainer.vue': { default: { setup: (_: any, ctx: any) => () => vue.h('main', ctx.slots.default?.()) } },
        '@/core/services/ApiService': { default: api }, '@/core/services/ApiErrors': errors,
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 }, term: () => 'Classes' }) },
    });
}
function pick(s: any, name = 'sample.xlsx') {
    const input = s.all((n: any) => n.tag === 'input' && n.props.type === 'file')[0];
    input.props.onChange({ target: { files: [new File(['made-up'], name)] } });
}
test('file matching shows samples, eight fields and a second guardian; every choice reaches preview', async () => {
    const calls: any[] = [];
    const s = await screen({ post: async (url: string, body: FormData) => { calls.push({ url, body }); return { data: { data: url.endsWith('/columns') ? inspection : preview } }; } });
    try {
        pick(s); await flush();
        assert.equal(calls[0].url.endsWith('/columns'), true);
        assert.match(s.text(), /Value 1.*Value 2.*Value 3/);
        assert.equal(s.all((n: any) => n.tag === 'select').length, 12);
        const first = s.all((n: any) => n.tag === 'select' && n.props.id === 'roster-column-student_first_name')[0];
        chooseOption(first, 2); await flush();
        click(s.button('Preview')); await flush();
        assert.equal(JSON.parse(calls[1].body.get('mapping') as string).student_first_name, 2);
        assert.match(s.text(), /Import this roster/);
        chooseOption(first, 1); await flush();
        assert.equal(s.all((n: any) => n.tag === 'button' && n.textContent.includes('Import this roster')).length, 0);
    } finally { s.unmount(); }
});
test('missing required column and duplicate choices stop at matching; optional columns can be absent', async () => {
    const calls: any[] = [];
    const s = await screen({ post: async (url: string, body: any) => { calls.push(url); return { data: { data: { ...inspection, mapping: { ...mapping, student_first_name: null } } } }; } });
    try {
        pick(s, 'sample.csv'); await flush();
        assert.equal(s.button('Preview').disabled, true);
        assert.match(s.text(), /Choose a column for Student first name before previewing/);
        const first = s.all((n: any) => n.tag === 'select' && n.props.id === 'roster-column-student_first_name')[0];
        chooseOption(first, 0); await flush();
        assert.equal(s.button('Preview').disabled, true);
        assert.match(s.text(), /Each file column can fill only one field/);
        chooseOption(first, 1); await flush();
        assert.equal(s.button('Preview').disabled, false);
        assert.equal(calls.length, 1);
        assert.match(s.text(), /Not in this file/);
    } finally { s.unmount(); }
});
test('a different file discards mapping and a late column response cannot replace the new file', async () => {
    const late = deferred<any>(); let count = 0;
    const s = await screen({ post: async () => ++count === 1 ? late.promise : { data: { data: { ...inspection, headers: ['New Class', 'New Name'], remembered: true } } } });
    try {
        pick(s, 'old.csv'); await flush();
        pick(s, 'new.xlsx'); await flush();
        assert.match(s.text(), /New Class/);
        late.resolve({ data: { data: inspection } }); await flush();
        assert.doesNotMatch(s.text(), /Homeroom/);
        assert.match(s.text(), /Remembered/);
    } finally { s.unmount(); }
});
