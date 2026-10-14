import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mountSfc, click, type, flush } from './support/mountSfc.ts';
const require = createRequire(import.meta.url);
const vue = require('vue');
const configuration = () => ({ revision: 1, grades: ['Pre-K', 'KG', '1st', '12th'], can_manage_bands: true, subject_names: ["Qur'an", 'Mathematics'], draft_effective_on: null, history: [{ actor: 'Example Staff', at: '2026-10-14T12:00:00Z', action: 'Saved criteria' }], bands: [{ id: 1, name: 'All grades', from_grade: 'Pre-K', to_grade: '12th', is_default: true, frozen: false, can_edit_behaviours: true, subjects: [{ name: 'Mathematics', criteria: ['Number Sense'], can_edit: true }], behaviours: ['Works well with others'] }] });

test('office criteria editor saves ordered curriculum, learning behaviours and band metadata', async () => {
    const writes: any[] = [];
    const s = await mountSfc('components/classes/ReportCardCriteriaEditor.vue', { schoolId: 1 }, { vue, '@/core/services/ApiService': { default: {
        get: async () => ({ data: { data: configuration() } }),
        put: async (url: string, body: any) => { writes.push({ url, body }); return { data: { data: { ...configuration(), bands: body.bands, revision: 2 } } }; },
    } } });
    try {
        await flush(); assert.match(s.text(), /Example Staff.*Saved criteria/);
        type(s.all(n => n.props['aria-label'] === 'Band name 1')[0], 'Primary grades');
        type(s.all(n => n.props['aria-label'] === 'Criterion 1 for Mathematics')[0], 'Problem solving');
        click(s.button('Add criterion')); await flush();
        type(s.all(n => n.props['aria-label'] === 'Criterion 2 for Mathematics')[0], 'Number sense');
        click(s.button('Move criterion up')); await flush();
        click(s.button('Save criteria')); await flush();
        assert.equal(writes.length, 1); assert.equal(writes[0].url, '/api/admin/masjids/1/report-card-criteria');
        assert.equal(writes[0].body.bands[0].name, 'Primary grades');
        assert.deepEqual(writes[0].body.bands[0].subjects[0].criteria, ['Number sense', 'Problem solving']);
        assert.deepEqual(writes[0].body.bands[0].behaviours, ['Works well with others']);
        assert.equal(writes[0].body.revision, 1); assert.match(s.text(), /Saved/);
    } finally { s.unmount(); }
});

test('frozen criteria explains the lock and lets staff save a next-term draft', async () => {
    const config = configuration(); config.bands[0].frozen = true;
    const writes: any[] = [];
    const s = await mountSfc('components/classes/ReportCardCriteriaEditor.vue', { schoolId: 1, groupId: 2 }, { vue, '@/core/services/ApiService': { default: {
        get: async () => ({ data: { data: config } }), put: async (url: string, body: any) => { writes.push({ url, body }); return { data: { data: { ...config, draft_effective_on: '2026-11-01' } } }; },
    } } });
    try {
        await flush(); assert.match(s.text(), /sent.*read-only/i);
        assert.equal(s.all(n => n.props['aria-label'] === 'Criterion 1 for Mathematics')[0].disabled, true);
        click(s.button('Changes apply from next term')); await flush();
        assert.equal(s.all(n => n.props['aria-label'] === 'Criterion 1 for Mathematics')[0].disabled, false);
        type(s.all(n => n.props['aria-label'] === 'Criterion 1 for Mathematics')[0], 'Next term criterion');
        click(s.button('Save criteria')); await flush();
        assert.equal(writes[0].url, '/api/teacher/masjids/1/groups/2/report-card-criteria');
        assert.equal(writes[0].body.next_term, true); assert.match(s.text(), /2026-11-01/);
    } finally { s.unmount(); }
});

test('teacher editor hides band controls and disables subjects outside their assignment', async () => {
    const config = configuration(); config.can_manage_bands = false; config.bands[0].subjects[0].can_edit = false; config.bands[0].can_edit_behaviours = false;
    const s = await mountSfc('components/classes/ReportCardCriteriaEditor.vue', { schoolId: 1, groupId: 2 }, { vue, '@/core/services/ApiService': { default: { get: async () => ({ data: { data: config } }) } } });
    try { await flush(); assert.equal(s.all(n => n.props['aria-label'] === 'Band name 1').length, 0); assert.equal(s.all(n => n.props['aria-label'] === 'Criterion 1 for Mathematics')[0].disabled, true); assert.equal(s.all(n => n.tag === 'button' && n.textContent === 'Add band').length, 0); }
    finally { s.unmount(); }
});

test('server validation remains visible and does not replace unsaved criteria', async () => {
    const s = await mountSfc('components/classes/ReportCardCriteriaEditor.vue', { schoolId: 1 }, { vue, '@/core/services/ApiService': { default: { get: async () => ({ data: { data: configuration() } }), put: async () => { throw { response: { data: { errors: { bands: ['Ranges must have no gaps.'] } } } }; } } } });
    try { await flush(); type(s.all(n => n.props['aria-label'] === 'Criterion 1 for Mathematics')[0], 'Unsaved text'); click(s.button('Save criteria')); await flush(); assert.match(s.text(), /Ranges must have no gaps/); assert.equal(s.all(n => n.props['aria-label'] === 'Criterion 1 for Mathematics')[0].value, 'Unsaved text'); }
    finally { s.unmount(); }
});

test('office can split a range beside its original band and merge it back', async () => {
    const writes: any[] = [];
    const s = await mountSfc('components/classes/ReportCardCriteriaEditor.vue', { schoolId: 1 }, { vue, '@/core/services/ApiService': { default: {
        get: async () => ({ data: { data: configuration() } }),
        put: async (_: string, body: any) => { writes.push(body); return { data: { data: { ...configuration(), bands: body.bands } } }; },
    } } });
    try {
        await flush(); click(s.button('Split band')); await flush();
        assert.equal(s.all(n => n.props['aria-label'] === 'Band name 2').length, 1);
        click(s.button('Save criteria')); await flush();
        assert.equal(writes[0].bands[0].to_grade, 'Pre-K'); assert.equal(writes[0].bands[1].from_grade, 'KG');
        assert.equal(writes[0].bands[1].id, null); assert.equal(writes[0].bands[1].is_default, false);
        click(s.all(n => n.tag === 'button' && n.textContent === 'Remove band')[1]); await flush();
        click(s.button('Save criteria')); await flush();
        assert.equal(writes[1].bands.length, 1); assert.equal(writes[1].bands[0].to_grade, '12th');
    } finally { s.unmount(); }
});

test('a new band in a saved next-term draft uses its own teacher permissions', async () => {
    const config: any = configuration(); config.can_manage_bands = false;
    config.draft_effective_on = '2026-11-01';
    config.draft_bands = [{ ...config.bands[0], id: null, name: 'New range', can_edit_behaviours: false, editable_subject_names: [], subjects: [{ name: 'Mathematics', criteria: ['Future criterion'], can_edit: false }] }];
    const s = await mountSfc('components/classes/ReportCardCriteriaEditor.vue', { schoolId: 1, groupId: 2 }, { vue, '@/core/services/ApiService': { default: { get: async () => ({ data: { data: config } }) } } });
    try { await flush(); assert.match(s.text(), /New range/); assert.equal(s.all(n => n.props['aria-label'] === 'Criterion 1 for Mathematics')[0].disabled, true); }
    finally { s.unmount(); }
});
