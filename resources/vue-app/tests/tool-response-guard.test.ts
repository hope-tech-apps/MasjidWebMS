import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import * as pinia from 'pinia';
import { deferred, loadTs } from './support/mountSfc.ts';
import { modulesFor } from './support/batch3Modules.ts';

async function guardHarness() {
    const enabled = vue.ref(true);
    const context = vue.ref('class:2/subject:102');
    const selection = vue.ref('arabic/student:9/week:2026-09-27');
    const disposals: (() => void)[] = [];
    const { useToolResponseGuard } = await loadTs('composables/useToolResponseGuard.ts', {
        vue: { ...vue, onBeforeUnmount: (fn: () => void) => disposals.push(fn) },
    });
    const scope = vue.effectScope();
    const capture = scope.run(() => useToolResponseGuard(() => enabled.value, () => context.value));
    return { enabled, context, selection, capture: (channel = 'tracker') => capture(() => selection.value, channel), invalidate: (channel: string) => capture.invalidate(channel), dispose: () => { disposals.forEach(fn => fn()); scope.stop(); } };
}

test('tool guard drops every stale class, subject, alphabet, student and week, including leave then return', async () => {
    for (const dimension of ['class', 'subject', 'alphabet', 'student', 'week']) {
        const h = await guardHarness();
        try {
            const response = h.capture(); assert.equal(response(), true);
            const ref = ['class', 'subject'].includes(dimension) ? h.context : h.selection;
            const original = ref.value; ref.value += `/${dimension}:changed`;
            assert.equal(response(), false); ref.value = original;
            assert.equal(response(), false, 'returning to the same identity does not revive the request');
        } finally { h.dispose(); }
    }
});
test('tool guard owns latest requests per channel, retains independent reads and drops on disposal', async () => {
    const h = await guardHarness();
    const first = h.capture(); const overview = h.capture('overview'); const second = h.capture();
    assert.equal(first(), false); assert.equal(second(), true); assert.equal(overview(), true);
    h.dispose(); assert.equal(second(), false); assert.equal(overview(), false);
});
test('tool guard preserves OFF sequencing, accepts initial capability discovery and rejects ON flag lifetimes', async () => {
    const h = await guardHarness();
    try {
        h.enabled.value = false;
        const legacy = h.capture(); h.selection.value = 'english/student:10'; h.context.value = 'class:3'; h.capture();
        assert.equal(legacy(), true, 'legacy OFF sequences remain accepting');
        const bootstrap = h.capture('points'); h.enabled.value = true; assert.equal(bootstrap(), true);
        const on = h.capture(); h.enabled.value = false; assert.equal(on(), false); h.enabled.value = true; assert.equal(on(), false);
    } finally { h.dispose(); }
    const off = await guardHarness(); off.enabled.value = false;
    const legacyAfterLeave = off.capture(); off.dispose(); assert.equal(legacyAfterLeave(), true);
});

test('office store assignments reject stale Hifdh entries/progress, Points awards/totals and skills', async () => {
    for (const [file, exportName, action, args, state, data] of [
        ['hifzStore', 'useHifzStore', 'fetchEntries', [2, 1], 'entriesPaginated', { data: [{ id: 1 }], total: 1 }],
        ['hifzStore', 'useHifzStore', 'fetchProgress', [2, 9], 'progressByMembership', { position: { surah: 1, ayah: 3 } }],
        ['behaviorStore', 'useBehaviorStore', 'fetchAwards', [2, 1], 'awardsPaginated', { data: [{ id: 1 }], total: 1 }],
        ['behaviorStore', 'useBehaviorStore', 'fetchSummary', [2, 9], 'summaries', { points: 7 }],
        ['behaviorStore', 'useBehaviorStore', 'fetchSkills', [false], 'skills', { data: [{ id: 1, label: 'Practice skill' }] }],
    ] as const) {
        pinia.setActivePinia(pinia.createPinia());
        const late = deferred();
        const path = `stores/masjid/${file}.ts`;
        const loaded = await loadTs(path, await modulesFor(path, { pinia,
            '../masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 } }) },
            '@/core/services/ApiService': { default: { get: () => late.promise } },
        }));
        const store = loaded[exportName]();
        const before = JSON.stringify(store[state]);
        let current = true;
        const request = store[action](...args, () => current);
        current = false; late.resolve({ data: { status: 'success', data, meta: {} } }); await request;
        assert.equal(JSON.stringify(store[state]), before, `${file}.${action} cannot write the shared state after its view leaves`);
    }
});

test('save redesign 5 OFF: a continuation after disposal creates no selection watcher', async () => {
    const disposals: (() => void)[] = []; let watches = 0;
    const loaded = await loadTs('composables/useToolResponseGuard.ts', {
        vue: { ...vue, watch: (...args: any[]) => { watches++; return (vue.watch as any)(...args); }, onBeforeUnmount: (fn: () => void) => disposals.push(fn) },
    });
    const scope = vue.effectScope(); const student = vue.ref(9);
    const capture = scope.run(() => loaded.useToolResponseGuard(() => false, () => 'class:2'));
    const first = capture(); disposals.forEach(fn => fn()); scope.stop();
    assert.equal(first(), true, 'legacy response is still applied');
    const before = watches;
    const progress = capture(() => student.value, 'student:9');
    assert.equal(progress(), true, 'legacy chained progress is still applied');
    assert.equal(watches, before, 'cleanup cannot be followed by new subscriptions');
});

test('save context never sequences away successes and separates current owner from departed editor', async () => {
    const disposals: (() => void)[] = []; let watches = 0;
    const { useToolSaveContext } = await loadTs('composables/useToolResponseGuard.ts', {
        vue: { ...vue, watch: (...args: any[]) => { watches++; return (vue.watch as any)(...args); }, onBeforeUnmount: (fn: () => void) => disposals.push(fn) },
    });
    const scope = vue.effectScope(); const owner = vue.ref('class:2'); const view = vue.ref('points'); const student = vue.ref(9);
    const capture = scope.run(() => useToolSaveContext(() => true, () => owner.value, () => view.value));
    const first = capture(() => student.value); const second = capture(() => student.value);
    assert.equal(first.editor(), true); assert.equal(second.editor(), true, 'saves do not compete for a sequence');
    student.value = 10; student.value = 9;
    assert.equal(first.editor(), false); assert.equal(first.reconcile(), true);
    view.value = 'roster'; view.value = 'points'; assert.equal(second.editor(), false); assert.equal(second.reconcile(), true);
    assert.equal(first.finish(), false, 'newer operation owns the busy flag');
    assert.equal(second.finish(), true); disposals.forEach(fn => fn()); scope.stop();
    assert.equal(first.reconcile(), false); const before = watches; capture(); assert.equal(watches, before);
});

test('read invalidation rejects only the pre-save channel and allocates nothing', async () => {
    const h = await guardHarness();
    try {
        const tracker = h.capture(); const skills = h.capture('skills');
        h.invalidate('tracker');
        assert.equal(tracker(), false); assert.equal(skills(), true);
        h.invalidate('unused');
    } finally { h.dispose(); }
});

for (const off of [false, true]) test(`review3 snapshots ${off ? 'OFF' : 'ON'}: order belongs to data across save actions, with final repair after a newer failure`, async () => {
    const disposals: (() => void)[] = [];
    const { useToolSaveContext } = await loadTs('composables/useToolResponseGuard.ts', {
        vue: { ...vue, onBeforeUnmount: (fn: () => void) => disposals.push(fn) },
    });
    const scope = vue.effectScope(); const student = vue.ref(9); let reads = 0;
    const capture = scope.run(() => useToolSaveContext(() => !off, () => 'class:2', () => 'letters'));
    const snapshot = { key: () => `tracker:${student.value}`, refresh: () => { reads++; } };
    try {
        const first = capture(() => student.value, 'advance', snapshot);
        const second = capture(() => student.value, 'masterAll', snapshot);
        assert.equal(second.snapshot(), true); second.finish();
        assert.equal(first.snapshot(), off); first.finish(); assert.equal(reads, off ? 0 : 1, 'a discarded successful snapshot is re-read: send order is not server order');
        const third = capture(() => student.value, 'saveDrillNote', snapshot);
        const fourth = capture(() => student.value, 'masterGroup', snapshot);
        assert.equal(third.snapshot(), off); third.finish(); assert.equal(reads, off ? 0 : 1, 'wait for the last pending save');
        fourth.finish(); assert.equal(reads, off ? 0 : 2, 'a rejected newer save still requires reading the older successful write');
        const other = capture(() => student.value, 'advance', snapshot);
        const independent = capture(() => student.value, 'setStage', { key: () => 'stage', refresh: () => { reads++; } });
        assert.equal(other.snapshot(), true); other.finish(); independent.snapshot(); independent.finish();
        const leaving = capture(() => student.value, 'advance', snapshot);
        student.value = 10; assert.equal(leaving.snapshot(), off); leaving.finish(); assert.equal(reads, off ? 0 : 2, 'do not repair a different student');
        const disposed = capture(() => student.value, 'advance', snapshot);
        disposals.forEach(fn => fn()); scope.stop(); assert.equal(disposed.snapshot(), off); disposed.finish();
        assert.equal(reads, off ? 0 : 2, 'no read after disposal');
    } finally { disposals.forEach(fn => fn()); scope.stop(); }
});

for (const on of [true, false]) test(`review4 snapshot matrix ${on ? 'ON' : 'OFF'}: every three-save arrival order and success/refusal combination`, async () => {
    const disposals: (() => void)[] = [];
    const { useToolSaveContext } = await loadTs('composables/useToolResponseGuard.ts', {
        vue: { ...vue, onBeforeUnmount: (fn: () => void) => disposals.push(fn) },
    });
    const scope = vue.effectScope(); let reads = 0;
    const capture = scope.run(() => useToolSaveContext(() => on, () => 'class:2', () => 'letters'));
    try {
        for (const order of [[0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0]]) for (let successes = 0; successes < 8; successes++) {
            const before = reads;
            const saves = ['advance', 'masterAll', 'saveDrillNote'].map(action => capture(() => 9, action, { key: () => 'tracker:9', refresh: () => { reads++; } }));
            for (const [at, i] of order.entries()) {
                if (successes & (1 << i)) assert.equal(saves[i].snapshot(), !on || i === 2);
                saves[i].finish();
                const needsRepair = on && !!(successes & 3);
                assert.equal(reads - before, at === 2 && needsRepair ? 1 : 0, `arrival ${order}, successes ${successes}: one repair after all settle`);
            }
        }
    } finally { disposals.forEach(fn => fn()); scope.stop(); }
});
