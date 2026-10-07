import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { chooseOption, click, compileSfc, deferred, flush, mountSfc } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');
const pageOf = (rows: any[]) => ({ data: rows, current_page: 1, per_page: 9, total: rows.length });
const container = {
    props: ['title', 'paginationOptions'], emits: ['pageChange'],
    render(this: any) { return vue.h('div', [vue.h('h1', this.title), this.$slots.default?.()]); },
};

async function screen(area: string) {
    const singular = area === 'services' ? 'Service' : 'Announcement';
    const org = vue.reactive({ masjid: { id: 3, timezone: 'UTC' } });
    const active = [{ id: 1, title: 'Current item', deleted_at: null, end_date: '2999-01-01' }];
    const archived = [{ id: 2, title: 'Archived item', deleted_at: '2026-10-01T12:00:00Z', end_date: '2020-01-01' }];
    const calls: any[] = [];
    const dialogs: any[] = [];
    const answer = { confirm: false, dialog: null as any, post: null as any, get: null as any };
    const api = {
        get: async (url: string) => {
            calls.push(['GET', url]);
            if (answer.get) return answer.get(url);
            return { data: { status: 'success', data: pageOf(url.includes('/archived') ? archived : active) } };
        },
        post: async (url: string) => {
            calls.push(['POST', url]);
            if (answer.post) return answer.post();
            active.push({ ...archived[0], deleted_at: null });
            archived.splice(0);
            return { data: { status: 'success' } };
        },
    };
    // These doubles let the original screens mount so the missing filter is the red failure.
    const store = vue.reactive({
        [`${area}Paginated`]: pageOf(active),
        [`fetchMasjid${singular}sPaginated`]: async () => {},
    });
    const card = await compileSfc(`components/data_cards/${singular}Card.vue`, {
        [`@/core/types/data/masjid-related/${singular}`]: {},
    });
    const mounted = await mountSfc(`views/dashboard/${area}/${singular}sView.vue`, {}, {
        [`@/components/data_cards/${singular}Card.vue`]: { default: card },
        '@/components/PageDataContainer.vue': { default: container },
        [`@/stores/masjid/${area}Store`]: { [`use${singular}sStore`]: () => store },
        '@/stores/masjidStore': { useMasjidStore: () => org },
        '@/core/services/ApiService': { default: api },
        '@/core/plugins/SweetAlerts2': {
            QSwal: { fire: async (options: any) => { dialogs.push(options); return answer.dialog ? answer.dialog() : { isConfirmed: answer.confirm }; } },
        },
        '@/core/types/data/masjid-related/Announcement': {},
        [`@/core/types/data/masjid-related/${singular}`]: {},
        '@/core/types/elements/Pagination': {},
        'vue-router': { useRouter: () => ({ push: () => {} }) },
    });
    await flush();
    const filter = () => {
        const fields = mounted.all((n) => n.tag === 'select');
        assert.equal(fields.length, 1, 'a Status filter offers Current and Archived');
        return fields[0];
    };
    return { mounted, calls, dialogs, answer, org, filter };
}

for (const area of ['services', 'announcements']) {
    test(`${area}: filter, archived date, cancelled confirm and confirmed restore back in Current`, async () => {
        const s = await screen(area);
        try {
            assert.match(s.mounted.text(), /Current item/);
            chooseOption(s.filter(), 'archived');
            await flush();
            assert.match(s.mounted.text(), /Archived item/);
            assert.doesNotMatch(s.mounted.text(), /Current item/);
            assert.match(s.mounted.text(), /Archived on/);
            assert.ok(s.mounted.all((n) => n.tag === 'time' && n.props.datetime === '2026-10-01T12:00:00Z').length);
            assert.doesNotMatch(s.mounted.text(), /Read More/);
            click(s.mounted.button('Restore'));
            await flush();
            assert.equal(s.calls.filter((c) => c[0] === 'POST').length, 0);
            assert.equal(s.dialogs[0].confirmButtonText, 'Restore');
            assert.equal(s.dialogs[0].cancelButtonText, 'Cancel');
            assert.match(s.dialogs[0].text, /public website/);
            assert.match(s.dialogs[0].text, /refresh/);
            if (area === 'announcements') {
                assert.match(s.dialogs[0].text, /expired announcements stay expired/);
                assert.match(s.dialogs[0].text, /No broadcast, push, email or SMS is sent/);
            }
            s.answer.confirm = true;
            click(s.mounted.button('Restore'));
            await flush();
            assert.deepEqual(s.calls.filter((c) => c[0] === 'POST'), [['POST', `/api/admin/masjids/3/${area}/2/restore`]]);
            assert.equal(s.calls.at(-1)?.[1], `/api/admin/masjids/3/${area}?page=1`);
            assert.match(s.mounted.text(), /Archived item/);
            assert.doesNotMatch(s.mounted.text(), /Archived on/);
            assert.doesNotMatch(s.mounted.text(), /Restore/);
            assert.match(s.mounted.text(), /restored successfully/);
            if (area === 'announcements') assert.match(s.mounted.text(), /Expired — dates unchanged\./);
        } finally { s.mounted.unmount(); }
    });

    test(`${area}: a failed restore stays archived and shows the failure`, async () => {
        const s = await screen(area);
        try {
            chooseOption(s.filter(), 'archived');
            await flush();
            s.answer.confirm = true;
            s.answer.post = () => Promise.reject(new Error('Request failed'));
            click(s.mounted.button('Restore'));
            await flush();
            assert.match(s.mounted.text(), /Archived on/);
            assert.match(s.mounted.text(), /Could not restore/);
            assert.match(s.mounted.text(), /Archived item/);
        } finally { s.mounted.unmount(); }
    });

    test(`${area}: duplicate taps send one restore and an organisation switch clears the old row`, async () => {
        const s = await screen(area);
        try {
            chooseOption(s.filter(), 'archived');
            await flush();
            s.answer.confirm = true;
            const pending = deferred();
            s.answer.post = () => pending.promise;
            click(s.mounted.button('Restore'));
            click(s.mounted.button('Restore'));
            await flush();
            assert.equal(s.calls.filter((c) => c[0] === 'POST').length, 1);
            pending.reject(new Error('Request failed'));
            await flush();
            s.org.masjid.id = 4;
            // Watch must remove the previous organisation's item before it can be acted on.
            s.answer.get = async () => ({ data: { status: 'success', data: pageOf([]) } });
            await flush();
            assert.doesNotMatch(s.mounted.text(), /Archived item/);
            assert.equal(s.calls.filter((c) => c[0] === 'POST').length, 1);
        } finally { s.mounted.unmount(); }
    });

    test(`${area}: switching organisation while confirmation is open prevents the restore`, async () => {
        const s = await screen(area);
        try {
            chooseOption(s.filter(), 'archived');
            await flush();
            const pending = deferred();
            s.answer.dialog = () => pending.promise;
            click(s.mounted.button('Restore'));
            await flush();
            s.answer.get = async () => ({ data: { status: 'success', data: pageOf([]) } });
            s.org.masjid.id = 4;
            await flush();
            pending.resolve({ isConfirmed: true });
            await flush();
            assert.equal(s.calls.filter((c) => c[0] === 'POST').length, 0);
            assert.doesNotMatch(s.mounted.text(), /Archived item/);
        } finally { s.mounted.unmount(); }
    });

    test(`${area}: a late archived response cannot overwrite a newer list`, async () => {
        const s = await screen(area);
        try {
            const pending = deferred();
            s.answer.get = (url: string) => url.includes('/archived') ? pending.promise : Promise.resolve({
                data: { status: 'success', data: pageOf([{ id: 1, title: 'Current item', deleted_at: null }]) },
            });
            chooseOption(s.filter(), 'archived');
            await flush();
            chooseOption(s.filter(), 'current');
            await flush();
            pending.resolve({ data: { status: 'success', data: pageOf([{ id: 2, title: 'Stale archived item', deleted_at: '2026-10-01' }]) } });
            await flush();
            assert.match(s.mounted.text(), /Current item/);
            assert.doesNotMatch(s.mounted.text(), /Stale archived item/);
        } finally { s.mounted.unmount(); }
    });
}
