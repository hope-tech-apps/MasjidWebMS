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
    return { enabled, context, selection, capture: (channel = 'tracker') => capture(() => selection.value, channel), dispose: () => { disposals.forEach(fn => fn()); scope.stop(); } };
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
