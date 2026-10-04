/**
 * The Teachers column of the office's class list: core/helpers/classTeachers.ts, and
 * views/dashboard/GroupsView.vue MOUNTED from its .vue file with the store answering what the
 * server answers (tests/support/mountSfc.ts says how, with no DOM).
 *
 * The helper says what one line reads. Only the mounted screen can say that a class with two
 * teachers draws two lines, that a class with none draws a dash, and that the cell holds words
 * and no control.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import * as ApiErrors from '../core/services/ApiErrors.ts';
import * as classTeachers from '../core/helpers/classTeachers.ts';
import { ALL_SUBJECTS_TEXT, classTeacherLines, subjectsText } from '../core/helpers/classTeachers.ts';
import { flush, mountSfc } from './support/mountSfc.ts';
import type { Mounted, Node } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');
const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');

const quran = { value: 'quran', label: "Qur'an" };
const arabic = { value: 'arabic', label: 'Arabic' };
const islamic = { value: 'islamic_studies', label: 'Islamic Studies' };

// ------------------------------------------------------------------------------------- the helper

test('the subjects read in the server\'s words and order, joined by commas', () => {
    assert.equal(subjectsText([quran, arabic]), "Qur'an, Arabic");
    assert.equal(subjectsText([islamic]), 'Islamic Studies');
    // The order is the server's: nothing here sorts, so nothing here can disagree with it.
    assert.equal(subjectsText([arabic, quran]), "Arabic, Qur'an");
});

test('a teacher of the whole class reads "All subjects": null, an empty list, or no key at all', () => {
    for (const subjects of [null, [], undefined]) {
        assert.equal(subjectsText(subjects as any), 'All subjects', JSON.stringify(subjects));
    }

    // The same words the Teachers form puts beside a class with no subject ticked.
    assert.equal(ALL_SUBJECTS_TEXT, 'All subjects');
    assert.ok(read('../views/dashboard/TeachersView.vue').includes(`? '' : '${ALL_SUBJECTS_TEXT}' }}`));
});

test('a subject that came without a label is shown by its value, never dropped into "All subjects"', () => {
    assert.equal(subjectsText([{ value: 'science', label: '' }]), 'science');
    assert.equal(subjectsText([quran, { value: 'science' } as any]), "Qur'an, science");
});

test('one line per teacher, in the order given, each with what they teach in that class', () => {
    const lines = classTeacherLines([
        { id: 4, name: 'Amina Farouk', subjects: null },
        { id: 9, name: 'Maryam Saleh', subjects: [islamic] },
        { id: 2, name: 'Zaynab Idris', subjects: [quran, arabic] },
    ]);

    assert.deepEqual(lines, [
        { id: 4, name: 'Amina Farouk', subjects: 'All subjects' },
        { id: 9, name: 'Maryam Saleh', subjects: 'Islamic Studies' },
        { id: 2, name: 'Zaynab Idris', subjects: "Qur'an, Arabic" },
    ]);
});

test('a class nobody teaches, and a row that came without the key, have no lines', () => {
    for (const teachers of [[], undefined, null, 'x', {}]) {
        assert.deepEqual(classTeacherLines(teachers as any), [], JSON.stringify(teachers));
    }
});

// ------------------------------------------------------------------------------------- the screen

/** PageDataContainer without its card: the title and the body. */
const containerStub = {
    props: ['title', 'buttonProps', 'paginationOptions'],
    render(this: any) {
        return vue.h('div', [vue.h('h1', this.title), this.$slots.default?.()]);
    },
};

/**
 * <Teleport to="body"> drawn where it stands. The harness has no document to teleport into (its
 * renderer finds no target), and the add/edit dialog it holds is closed in every test here.
 */
const teleportInPlace = (_props: any, { slots }: any) => slots.default?.();

const group = (id: number, name: string, extra: Record<string, any> = {}) => ({
    id, masjid_id: 7, name, slug: name.toLowerCase().replace(/\s+/g, '-'), kind: 'class', position: id, description: null,
    is_active: true, starts_on: null, ends_on: null, created_at: '', updated_at: '', participants_count: 12, ...extra,
});

/** The office's class list as the server answers it: Grade 1 has two teachers, Grade 2 one, Grade 3 none. */
const page = [
    group(1, 'Grade 1', { teachers: [
        { id: 4, name: 'Amina Farouk', subjects: null },
        { id: 2, name: 'Zaynab Idris', subjects: [quran, arabic] },
    ] }),
    group(2, 'Grade 2', { teachers: [{ id: 9, name: 'Maryam Saleh', subjects: [islamic] }] }),
    group(3, 'Grade 3', { teachers: [] }),
    // A row with no `teachers` key: what store and update answer with.
    group(4, 'Grade 4'),
];

async function mount(rows: any[] = page): Promise<Mounted> {
    const store: any = vue.reactive({ groupsPaginated: undefined, groupsMeta: undefined });
    store.fetchGroups = async () => {
        store.groupsPaginated = { data: rows, current_page: 1, total: rows.length, per_page: 15 };
        store.groupsMeta = { group_label: 'Classrooms', kinds: ['general', 'class', 'halaqa', 'team'], roles: ['leader', 'member', 'guardian'] };
    };

    const screen = await mountSfc('views/dashboard/GroupsView.vue', {}, {
        'vue': { ...vue, Teleport: teleportInPlace },
        'sweetalert2': { default: { fire: async () => ({ isConfirmed: false }) } },
        '@/components/PageDataContainer.vue': { default: containerStub },
        '@/views/dashboard/groups/SchoolSubjectsCard.vue': { default: { render: () => null } },
        // Imported for their types only.
        '@/core/types/elements/Pagination': {},
        '@/core/types/data/masjid-related/Group': {},
        '@/core/constants/appConfigConstants': { LOCAL_STORAGE_KEYS: {} },
        '@/core/services/ApiErrors': ApiErrors,
        '@/core/helpers/classTeachers': classTeachers,
        '@/stores/authStore': { useAuthStore: () => ({}) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ orgType: 'school', term: (key: string) => (key === 'groups' ? 'Classrooms' : key) }) },
        '@/stores/masjid/groupsStore': { useGroupsStore: () => store },
    });
    await flush();

    return screen;
}

const hasClass = (n: Node, name: string): boolean => String(n.props.class ?? '').split(/\s+/).includes(name);
const under = (n: Node, test: (n: Node) => boolean, acc: Node[] = []): Node[] => {
    n.children.forEach((c) => { if (c.kind === 'el' && test(c)) acc.push(c); under(c, test, acc); });
    return acc;
};
const bodyRows = (screen: Mounted): Node[] => screen.all((n) => n.tag === 'tr' && n.parent?.tag === 'tbody');
/** The Teachers cell of the row whose class has this name. */
function teachersCell(screen: Mounted, className: string): Node {
    const row = bodyRows(screen).find((r) => r.textContent.startsWith(className));
    if (!row) throw new Error(`no row for ${className} (screen: ${screen.text()})`);
    const cells = under(row, (n) => n.tag === 'td' && hasClass(n, 'class-teachers'));
    assert.equal(cells.length, 1, `${className} has one Teachers cell`);

    return cells[0];
}
const lines = (cell: Node): Node[] => cell.children.filter((n) => n.kind === 'el' && hasClass(n, 'class-teacher'));

test('the list has a Teachers column, right after the name', async () => {
    const screen = await mount();

    const headings = screen.all((n) => n.tag === 'th').map((n) => n.textContent);
    assert.deepEqual(headings, ['Name', 'Teachers', 'Kind', 'Members', 'Status', 'Actions']);

    // The cell sits under its heading in every row.
    for (const row of bodyRows(screen)) {
        const cells = row.children.filter((n) => n.tag === 'td');
        assert.equal(cells.length, headings.length);
        assert.equal(hasClass(cells[1], 'class-teachers'), true);
    }

    screen.unmount();
});

test('a class with two teachers draws two lines, each a name with its subjects beside it in smaller muted text', async () => {
    const screen = await mount();
    const drawn = lines(teachersCell(screen, 'Grade 1'));

    assert.equal(drawn.length, 2);
    // One block per teacher, so the names stack.
    assert.deepEqual(drawn.map((line) => line.tag), ['div', 'div']);

    const parts = drawn.map((line) => line.children.filter((n) => n.kind === 'el'));
    assert.deepEqual(parts.map((p) => p.map((n) => n.textContent)), [
        ['Amina Farouk', 'All subjects'],
        ['Zaynab Idris', "Qur'an, Arabic"],
    ]);
    for (const [name, subjects] of parts) {
        assert.equal(hasClass(name, 'text-muted'), false, 'the name is not muted');
        assert.equal(hasClass(subjects, 'text-muted') && hasClass(subjects, 'small'), true, 'the subjects are');
    }

    assert.deepEqual(lines(teachersCell(screen, 'Grade 2')).map((line) => line.textContent), ['Maryam Saleh Islamic Studies']);
    // No dash beside a name.
    assert.doesNotMatch(teachersCell(screen, 'Grade 1').textContent, /—/);

    screen.unmount();
});

test('a class with no teacher draws a muted dash, and says so to a screen reader', async () => {
    const screen = await mount();

    for (const className of ['Grade 3', 'Grade 4']) {
        const cell = teachersCell(screen, className);
        assert.equal(lines(cell).length, 0, className);

        const dash = cell.children.filter((n) => n.kind === 'el');
        assert.equal(dash.length, 1, className);
        assert.equal(hasClass(dash[0], 'text-muted'), true);
        assert.equal(dash[0].props.title, 'No teacher assigned');

        const [mark, words] = dash[0].children.filter((n) => n.kind === 'el');
        assert.equal(mark.textContent, '—');
        assert.equal(mark.props['aria-hidden'], 'true');
        assert.equal(words.textContent, 'No teacher assigned');
        assert.equal(hasClass(words, 'visually-hidden'), true);
    }

    screen.unmount();
});

test('the whole cell is words: no link, no button, no field, nothing that answers a click', async () => {
    const screen = await mount();

    for (const row of bodyRows(screen)) {
        const cell = under(row, (n) => n.tag === 'td' && hasClass(n, 'class-teachers'))[0];
        const controls = under(cell, (n) => ['a', 'router-link', 'button', 'input', 'select', 'textarea'].includes(n.tag)
            || Object.keys(n.props).some((key) => /^on[A-Z]/.test(key)));
        assert.equal(controls.length, 0, row.textContent);
        assert.deepEqual([...new Set(under(cell, () => true).map((n) => n.tag))].sort(), cell.textContent.includes('—') ? ['span'] : ['div', 'span']);
    }

    screen.unmount();
});

test('on a narrow screen the subjects drop under the name instead of running off the edge', () => {
    const view = read('../views/dashboard/GroupsView.vue');
    const style = view.slice(view.indexOf('<style scoped>'));

    // A wrapping row per teacher: beside the name while there is room, under it when there is not.
    assert.match(style, /\.class-teacher \{[^}]*display: flex;[^}]*flex-wrap: wrap;[^}]*\}/);
    // The lines stack with a little air between them.
    assert.match(style, /\.class-teacher \+ \.class-teacher \{[^}]*margin-top:/);
    // Nothing forces a line onto one row.
    assert.doesNotMatch(style.slice(style.indexOf('.class-teachers'), style.indexOf('.stats-card')), /nowrap/);
});
