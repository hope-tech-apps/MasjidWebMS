import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { mountSfc, click, type, check, select, flush } from './support/mountSfc.ts';
const require = createRequire(import.meta.url);
const vue = require('vue');

test('school settings edit, add and remove scale rows and save the whole configuration', async () => {
    const writes: any[] = [];
    const configuration = { enabled: false, from_grade: '3rd', grades: ['2nd', '3rd'], can_enable: true, scale: [{ letter: 'A', minimum: 90 }, { letter: 'F', minimum: 0 }] };
    const s = await mountSfc('components/classes/LetterGradeSettings.vue', { schoolId: 1 }, {
        vue, '@/core/services/ApiService': { default: {
            get: async () => ({ data: { data: configuration } }),
            put: async (url: string, body: any) => { writes.push({ url, body }); return { data: { data: body } }; },
        } },
    });
    try {
        await flush(); assert.match(s.text(), /Letter grades from grade/);
        check(s.all(n => n.tag === 'input' && n.props.type === 'checkbox')[0], true);
        select(s.all(n => n.tag === 'select')[0], '2nd');
        type(s.all(n => n.props['aria-label'] === 'Letter 1')[0], 'Pass');
        click(s.button('Add row')); await flush();
        click(s.all(n => n.tag === 'button' && n.textContent === 'Remove row')[2]); await flush();
        click(s.button('Save letter grades')); await flush();
        assert.equal(writes.length, 1); assert.equal(writes[0].body.enabled, true);
        assert.equal(writes[0].body.from_grade, '2nd');
        assert.deepEqual(writes[0].body.scale, [{ letter: 'Pass', minimum: 90 }, { letter: 'F', minimum: 0 }]);
        assert.match(s.text(), /Saved/);
    } finally { s.unmount(); }
});

test('without dated terms settings explain setup and disable enabling', async () => {
    const s = await mountSfc('components/classes/LetterGradeSettings.vue', { schoolId: 1 }, {
        vue, '@/core/services/ApiService': { default: { get: async () => ({ data: { data: { enabled: false, from_grade: '3rd', grades: ['3rd'], can_enable: false, scale: [], setup_message: 'Add dated terms first.' } } }) } },
    });
    try { await flush(); assert.match(s.text(), /Add dated terms first/); assert.equal(s.all(n => n.props.type === 'checkbox')[0].disabled, true); }
    finally { s.unmount(); }
});

test('teacher box shows both grades, attribution, omitted pieces and required reason', async () => {
    const grade = { id: 3, subject: 'Science', can_fill: true, calculated_percentage: 88.4, calculated_letter: 'B+', override_letter: 'A−', override_reason: 'Additional evidence', override_by_user_id: 4, override_at: '2026-10-14T12:00:00Z', comment: 'Keep practising', excluded_work: [{ source: 'assignment', id: 9, title: 'Reading rubric', reason: 'Marked on 4-to-1 levels; no points percentage.' }] };
    const s = await mountSfc('components/classes/ReportLetterGrade.vue', { grade, scale: [{ letter: 'A−', minimum: 90 }], published: false }, { vue });
    try { assert.match(s.text(), /Worked out B\+ \(88.4%\)/); assert.match(s.text(), /Set by the teacher: A−.*Additional evidence/); assert.match(s.text(), /Reading rubric.*4-to-1 levels/); assert.equal(s.all(n => n.props['aria-label'] === 'Reason for Science')[0].props.required, true); }
    finally { s.unmount(); }
});

test('family card renders only final letter and comment, omitting absent percentage', async () => {
    const s = await mountSfc('components/classes/FamilyLetterGrades.vue', { grades: [{ subject: 'Science', final_letter: 'A−', percentage: null, comment: 'Keep practising' }, { subject: 'Arabic', final_letter: null, percentage: null, comment: 'Lovely reading this term' }] }, {
        vue, '@/views/family/familyI18n': { useFamilyLang: () => ({ t: (key: string) => ({ term_grade: 'Term grade', term_no_points: 'No points work in this term' }[key] ?? key) }) },
    });
    try { assert.match(s.text(), /Science.*A−.*Keep practising/); assert.doesNotMatch(s.text(), /No points work in this term/); assert.match(s.text(), /Arabic.*Lovely reading this term/); assert.equal((s.text().match(/Term grade/g) ?? []).length, 1); assert.doesNotMatch(s.text(), /%|Worked out|Set by|reason/); }
    finally { s.unmount(); }
});
