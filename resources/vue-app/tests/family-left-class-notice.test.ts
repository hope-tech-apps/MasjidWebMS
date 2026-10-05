/**
 * The "no consent" notice on the family's two class screens, MOUNTED: the list of classes
 * (FamilyHome.vue) and one class's Story tab (FamilyClass.vue).
 *
 * The server closes a class's story for two different families: one with no consent on file, and
 * one whose child has left the class (a leaving date ends the story whatever consent still stands
 * on the entry). Both arrive as `may_receive_feed: false`, and both were told "You have not given
 * consent", which is false of the second. The class payload now says which it is
 * (`in_class_now`), and the notice is drawn only for a class the family is still in.
 *
 * Each screen is compiled from its .vue file and given exactly the payload the server serves
 * (tests/support/mountSfc.ts says how, with no DOM). The class screen runs its real load chain
 * (views/family/familyClassRun.ts) against a scripted API. The language table is a stand-in that
 * answers each key with itself, so a notice is found by its key.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import * as behaviorSkills from '../core/helpers/behaviorSkills.ts';
import * as gradebook from '../core/helpers/gradebook.ts';
import * as letterRuns from '../core/helpers/letterRuns.ts';
import * as pointsWeek from '../core/helpers/pointsWeek.ts';
import * as storyEdit from '../core/helpers/storyEdit.ts';
import { flush, loadTs, mountSfc } from './support/mountSfc.ts';
import type { Mounted } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');

// The class screen listens for the page coming back into view. The stand-in document the mount
// helper supplies has no events, so it is given the two calls here, in this file's own process.
const page = (globalThis as any).document;
page.addEventListener ??= () => {};
page.removeEventListener ??= () => {};

const ok = (data: any, meta: any = {}) => ({ data: { status: 'success', data, meta } });
const rowsOf = (n: any) => (Array.isArray(n) ? n : Array.isArray(n?.data) ? n.data : []);
const stub = { render: () => null };

const BASE = '/api/family/masjids/1/groups';

/** One class as Family\GroupsController::serialize() serves it, for a parent with one child in it. */
function classPayload(id: number, name: string, over: Record<string, any> = {}) {
    return {
        id,
        name,
        kind: 'class',
        description: null,
        is_active: true,
        points_period: 'running',
        weekly_report: false,
        starts_on: null,
        ends_on: null,
        is_guardian: true,
        own_membership: null,
        children: [{ membership_id: id * 10, contact: { id: 7, first_name: 'Amina', last_name: 'Y', avatar: null } }],
        may_receive_feed: false,
        may_receive_media: false,
        in_class_now: true,
        ...over,
    };
}

/** The same class from a server that does not send the key yet. */
function withoutTheKey(payload: Record<string, any>) {
    const { in_class_now: _dropped, ...rest } = payload;

    return rest;
}

/** A family API that answers the list, each class, and an empty page for every per-child read. */
function familyApi(classes: any[], posts: any[] = []) {
    const urls: string[] = [];
    const api = {
        get: async (url: string) => {
            urls.push(url);
            if (url === BASE) return ok(classes);
            const one = classes.find((c) => url === `${BASE}/${c.id}`);
            if (one) return ok(one);
            if (url.endsWith('/posts')) return ok(posts, { story_reads: { enabled: false } });

            return ok([]);
        },
        post: async () => ok({}),
    };

    return { api, urls };
}

const lang = () => ({
    useFamilyLang: () => ({
        lang: vue.ref('en'),
        locale: vue.ref('en'),
        isRtl: vue.ref(false),
        dir: vue.ref('ltr'),
        t: (key: string, x?: string) => (x ? `${key}(${x})` : key),
        tCount: (key: string, n: number) => `${key}(${n})`,
        tMessage: (m: any) => m?.key ?? m?.text ?? '',
        tBoth: (key: string) => key,
        tMessageBoth: (m: any) => m?.key ?? m?.text ?? '',
    }),
});

const translation = () => ({
    useContentTranslation: () => ({
        loading: vue.ref(false),
        error: vue.ref(null),
        incomplete: vue.ref(false),
        showOriginal: vue.ref(false),
        hasTranslations: vue.ref(false),
        showing: vue.ref(false),
        showingLang: vue.ref(null),
        available: vue.ref(false),
        setAvailable: () => {},
        translate: async () => {},
        tx: (_key: string, text: any) => text,
    }),
});

const store = () => ({
    useFamilyStore: () => ({
        contactFor: () => ({ first_name: 'Maryam' }),
        handleAuthFailure: () => false,
    }),
});

async function mountHome(api: any): Promise<Mounted> {
    const screen = await mountSfc('views/family/FamilyHome.vue', {}, {
        '@/core/services/FamilyApiService': { default: api, rowsOf },
        '@/components/common/PersonAvatar.vue': { default: stub },
        '@/stores/familyStore': store(),
        '@/views/family/familyI18n': lang(),
        '@/views/family/FamilyLangPicker.vue': { default: stub },
        '@/views/family/useContentTranslation': translation(),
        'vue-router': { useRoute: () => ({ params: { masjidId: '1' } }), useRouter: () => ({ replace() {}, push() {} }) },
    });
    await flush();

    return screen;
}

async function mountClass(api: any, groupId: number): Promise<Mounted> {
    const apiModule = { default: api, rowsOf };
    const run = await loadTs('views/family/familyClassRun.ts', { '@/core/services/FamilyApiService': apiModule, vue });

    const screen = await mountSfc('views/family/FamilyClass.vue', {}, {
        '@/core/services/FamilyApiService': apiModule,
        '@/components/common/PersonAvatar.vue': { default: stub },
        '@/core/helpers/behaviorSkills': behaviorSkills,
        '@/core/helpers/pointsWeek': pointsWeek,
        '@/core/helpers/letterRuns': letterRuns,
        '@/core/helpers/gradebook': gradebook,
        '@/components/common/AvatarPicker.vue': { default: stub },
        '@/core/services/StudentApiService': { default: {} },
        '@/views/family/FamilyAttachment.vue': { default: stub },
        '@/views/family/FamilyBucks.vue': { default: stub },
        '@/components/common/MessageSignals.vue': { default: stub },
        '@/stores/familyStore': store(),
        '@/views/family/familyI18n': lang(),
        '@/views/family/FamilyLangPicker.vue': { default: stub },
        '@/views/family/familyClassRun': run,
        '@/views/family/useContentTranslation': translation(),
        '@/core/helpers/storyEdit': storyEdit,
        'vue-router': {
            useRoute: () => ({ params: { masjidId: '1', groupId: String(groupId) } }),
            useRouter: () => ({ replace() {}, push() {} }),
        },
    });
    await flush(12);

    return screen;
}

/** The card of one class on the list, by the class's name. */
function card(screen: Mounted, name: string) {
    const cards = screen.all((n) => n.tag === 'router-link' && n.textContent.includes(name));
    assert.equal(cards.length, 1, `one card says "${name}" (screen: ${screen.text()})`);

    return cards[0];
}

/** The Story tab's own section: the screen opens on it. */
function story(screen: Mounted) {
    const sections = screen.all((n) => n.tag === 'section');
    assert.equal(sections.length, 1, 'one tab is open');

    return sections[0];
}

// ------------------------------------------------------------------ the list of classes

test('the list: a class the family is in, with no consent on file, says so', async () => {
    const { api } = familyApi([classPayload(3, 'Grade 3')]);
    const screen = await mountHome(api);

    assert.match(card(screen, 'Grade 3').textContent, /home_no_consent/);
    screen.unmount();
});

test('the list: a class the child has left is listed with no notice, beside a class that still has one', async () => {
    // What a family meets after a move: the class left (consent was given there and is still on
    // file) and the class entered (none on file there yet).
    const { api } = familyApi([
        classPayload(3, 'Grade 3', { in_class_now: false }),
        classPayload(4, 'Grade 4'),
    ]);
    const screen = await mountHome(api);

    assert.doesNotMatch(card(screen, 'Grade 3').textContent, /home_no_consent/, 'the false sentence is gone');
    assert.match(card(screen, 'Grade 3').textContent, /Amina/, 'and the class is still theirs to open');
    assert.match(card(screen, 'Grade 4').textContent, /home_no_consent/, 'the class they are in still says it');
    assert.equal(screen.all((n) => String(n.props.class ?? '').includes('alert-warning')).length, 1);
    screen.unmount();
});

test('the list: a class whose story is open has no notice, in the class or out of it', async () => {
    const { api } = familyApi([classPayload(3, 'Grade 3', { may_receive_feed: true, may_receive_media: true })]);
    const screen = await mountHome(api);

    assert.doesNotMatch(screen.text(), /home_no_consent/);
    screen.unmount();
});

test('the list: a payload without the key draws the notice as it always did', async () => {
    const { api } = familyApi([withoutTheKey(classPayload(3, 'Grade 3'))]);
    const screen = await mountHome(api);

    assert.match(card(screen, 'Grade 3').textContent, /home_no_consent/);
    screen.unmount();
});

// ------------------------------------------------------------------ one class, the Story tab

test('the class: a family in the class with no consent on file is told so, and the story is not asked for', async () => {
    const { api, urls } = familyApi([classPayload(3, 'Grade 3')]);
    const screen = await mountClass(api, 3);

    assert.match(story(screen).textContent, /story_no_consent/);
    assert.doesNotMatch(screen.text(), /story_empty/);
    assert.equal(urls.filter((u) => u.endsWith('/posts')).length, 0, 'a 403 the parent was already told about is not asked for');
    screen.unmount();
});

test('the class: a class the child has left shows neither the consent notice nor "nothing posted yet"', async () => {
    const { api, urls } = familyApi([classPayload(3, 'Grade 3', { in_class_now: false })]);
    const screen = await mountClass(api, 3);

    assert.match(screen.text(), /Grade 3/, 'the class opened');
    assert.doesNotMatch(screen.text(), /story_no_consent/, 'the false sentence is gone');
    // The feed was never read, so the screen cannot know whether anything was posted: saying
    // "Nothing posted yet." would swap one false sentence for another.
    assert.doesNotMatch(screen.text(), /story_empty/);
    assert.equal(story(screen).textContent, '', 'the Story tab says nothing at all');
    assert.equal(urls.filter((u) => u.endsWith('/posts')).length, 0);
    // The rest of the class is still theirs: the child's own tabs are offered.
    assert.equal(screen.all((n) => n.tag === 'button' && n.textContent === 'tab_reports').length, 1);
    screen.unmount();
});

test('the class: an open story with nothing in it still says "nothing posted yet"', async () => {
    const { api, urls } = familyApi([classPayload(3, 'Grade 3', { may_receive_feed: true, may_receive_media: true })]);
    const screen = await mountClass(api, 3);

    assert.match(story(screen).textContent, /story_empty/);
    assert.doesNotMatch(screen.text(), /story_no_consent/);
    assert.equal(urls.filter((u) => u.endsWith('/posts')).length, 1);
    screen.unmount();
});

test('the class: an open story is drawn', async () => {
    const post = { id: 9, title: 'Trip photos', body: 'We went to the farm.', author: { name: 'Ms Huda' }, published_at: '2026-10-01T10:00:00Z', created_at: '2026-10-01T10:00:00Z', attachments: [] };
    const { api } = familyApi([classPayload(3, 'Grade 3', { may_receive_feed: true, may_receive_media: true })], [post]);
    const screen = await mountClass(api, 3);

    assert.match(story(screen).textContent, /Trip photos/);
    assert.doesNotMatch(screen.text(), /story_no_consent|story_empty/);
    screen.unmount();
});

test('the class: a payload without the key draws the notice as it always did', async () => {
    const { api } = familyApi([withoutTheKey(classPayload(3, 'Grade 3'))]);
    const screen = await mountClass(api, 3);

    assert.match(story(screen).textContent, /story_no_consent/);
    screen.unmount();
});
