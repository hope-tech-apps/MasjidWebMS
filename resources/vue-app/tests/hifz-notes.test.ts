import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chooseOption, click, flush, mountSfc } from './support/mountSfc.ts';
import { modulesFor } from './support/batch3Modules.ts';

/**
 * The teacher's Hifdh tab and the notes on a recitation.
 *
 * The form has taken a note since the tab shipped; the list under it showed the
 * portion, the quality and the day, and never the note. A Qur'an teacher wrote
 * about a child and could not find it again.
 */
const doc = (globalThis as any).document;
doc.addEventListener ??= () => {};
doc.removeEventListener ??= () => {};
doc.body = { style: {} };
(globalThis as any).window ??= { addEventListener() {}, removeEventListener() {} };
const route = { params: { masjidId: '1', groupId: '2' }, query: {} };
const router = { useRoute: () => route, useRouter: () => ({ replace: async () => {}, resolve: () => ({ href: '/' }) }) };
const ok = (data: any, meta: any = {}) => ({ data: { status: 'success', data, meta } });
const rowsOf = (data: any) => Array.isArray(data) ? data : data?.data ?? [];

const classData = { id: 2, name: 'Sample class', students: [{ membership_id: 9, contact: { first_name: 'Test student' } }] };
const row = (id: number, note: string | null) => ({
    id, membership_id: 9, kind: 'sabak', quality: 'good', whole_surah: false,
    from: { surah: 78, surah_name: 'An-Naba', ayah: 1 }, to: { surah: 78, surah_name: 'An-Naba', ayah: 10 },
    recited_at: '2026-10-01', note, heard_by: { id: 5, name: 'Test teacher' },
});

async function openHifdh(rows: any[], extra: Record<string, any> = {}) {
    const calls: { verb: string; url: string; body?: any }[] = [];
    const api = {
        get: async (url: string) => ok(url.endsWith('/hifz') ? rows : url.endsWith('/groups/2') ? classData : []),
        post: async (url: string, body: any) => { calls.push({ verb: 'post', url, body }); return ok({}); },
        ...extra,
    };
    const file = 'views/teacher/TeacherClass.vue';
    const screen = await mountSfc(file, {}, await modulesFor(file, {
        'vue-router': router,
        '@/core/services/TeacherApiService': { default: api, rowsOf },
        '@/stores/authStore': { useAuthStore: () => ({ dashboardMasjidId: 1 }) },
    }));
    await flush(); click(screen.button('Hifdh')); await flush();
    const student = screen.all((n: any) => n.tag === 'select' && n.children.some((o: any) => o.props.value === 9))[0];
    chooseOption(student, 9);
    await flush(12);
    return { screen, calls };
}

const rowsOn = (screen: any) => screen.all((n: any) => n.tag === 'li' && n.textContent.includes('Remove'));

test('a recitation that has a note shows it under its line, as the teacher typed it', async () => {
    const { screen } = await openHifdh([
        row(1, 'struggled with the waqf on ayah 12\nrevise at home'),
        row(2, null),
    ]);
    try {
        const rows = rowsOn(screen);
        assert.equal(rows.length, 2);
        assert.ok(rows[0].textContent.includes('struggled with the waqf on ayah 12'), rows[0].textContent);
        assert.ok(rows[0].textContent.includes('revise at home'), rows[0].textContent);
        // Her words are not inside the line the screen capitalises, and a line
        // break she typed is kept.
        const note = screen.all((n: any) => n.kind === 'el' && String(n.props.class ?? '').includes('hifz-note'));
        assert.equal(note.length, 1, 'only the row that has a note draws one');
        assert.equal(String(note[0].props.class).includes('text-capitalize'), false);
        assert.match(JSON.stringify(note[0].props.style ?? ''), /pre-wrap/);
        // The line itself still reads as it did: the portion first.
        assert.ok(rows[0].textContent.startsWith('New memorization:'), rows[0].textContent);
        assert.ok(rows[1].textContent.startsWith('New memorization:'), rows[1].textContent);
    } finally { screen.unmount(); }
});
