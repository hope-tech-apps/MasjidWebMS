import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mountSfc } from './support/mountSfc.ts';
import * as formTypes from '../core/types/data/masjid-related/Form.ts';

for (const on of [false, true]) test(`calendar choice help, calendar ${on}`, async () => {
    const s = await mountSfc('components/forms/FormFieldEditor.vue', {
        field: { name: 'days', label: 'School days', type: 'radio', optionsSource: 'school_meeting_days' },
        index: 0, total: 1, idPrefix: 'days', fieldTypes: [], conditionalSources: [],
        optionsSources: [{ key: 'school_meeting_days', label: 'School days', available: true }],
        calendarTermsOn: on,
    }, { '@/core/types/data/masjid-related/Form': formTypes });
    try {
        const expected = on
            ? 'Families will see the open school days in the next four weeks, listed by date. Days that pass, or that the office marks as no school, drop off the list by themselves.'
            : "Families will see the upcoming school days that aren't marked as no school, listed by date. Days that pass, or that the office later marks as no school, drop off the list by themselves.";
        assert.ok(s.text().replace(/\s+/g, ' ').includes(expected));
        assert.equal(s.text().includes('next four weeks'), on);
    } finally { s.unmount(); }
});
