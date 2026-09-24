/**
 * Studio's autosave rules (core/studio/autosave.ts), with a stub transport and
 * stub timers: a 409 disarms and is never retried, a step change is saved with
 * the last choice winning, and a failure is not repeated on its own.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createAutosave } from '../core/studio/autosave.ts';

type Draft = { lock_version: number; current_step: string };

/** A PATCH the test answers by hand. */
type Sent = { step: string | null; version: number; resolve: (draft: Draft) => void; reject: (error: unknown) => void };

function harness() {
    const sent: Sent[] = [];
    const timers = new Map<number, () => void>();
    let nextTimer = 1;
    const server = { lock_version: 1, current_step: 'foundation' };
    let edits = 0;
    let savedEdits = 0;
    const dirty = () => edits > savedEdits;
    let conflicts = 0;

    const armed = { value: true };
    const saveState = { value: 'idle' as string };
    const saveError = { value: null as string | null };

    const autosave = createAutosave<Draft>({
        debounceMs: 1500,
        armed,
        saveState: saveState as any,
        saveError,
        request: (step) => {
            if (!dirty() && !step) return null;
            const version = server.lock_version;
            const upTo = edits;
            return {
                send: () => new Promise<Draft>((resolve, reject) => { sent.push({ step, version, resolve, reject }); }),
                confirm: (draft) => { server.lock_version = draft.lock_version; server.current_step = draft.current_step; savedEdits = upTo; },
            };
        },
        dirty,
        serverStep: () => server.current_step as any,
        isConflict: (error) => (error as { status?: number })?.status === 409,
        onConflict: () => { conflicts++; },
        failureMessage: () => 'Not saved.',
        timers: {
            set: (run) => { const id = nextTimer++; timers.set(id, run); return id; },
            clear: (id) => { timers.delete(id as number); },
        },
    });

    return {
        autosave, sent, timers, server, armed, saveState, saveError,
        edit: () => { edits++; autosave.schedule(); },
        conflicts: () => conflicts,
        /** Fire every pending timer, as the pause running out would. */
        tick: () => { const due = [...timers.values()]; timers.clear(); due.forEach((run) => run()); },
    };
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('a 409 disarms the autosave: no retry is scheduled and later edits send nothing until reload', async () => {
    const h = harness();
    h.edit();
    h.tick();
    await settle();
    assert.equal(h.sent.length, 1);

    h.edit(); // typed while the doomed save is in flight
    h.sent[0].reject({ status: 409 });
    await settle();

    assert.equal(h.saveState.value, 'conflict');
    assert.equal(h.armed.value, false);
    assert.equal(h.conflicts(), 1);
    assert.equal(h.autosave.scheduled(), false, 'no retry may be waiting');
    assert.equal(h.timers.size, 0);

    h.edit();
    h.tick();
    await h.autosave.flush();
    await settle();
    assert.equal(h.sent.length, 1, 'nothing is sent again, with any version');
});

test('any other failure waits for the next edit or Retry, and Retry sends', async () => {
    const h = harness();
    h.edit();
    h.tick();
    await settle();
    h.sent[0].reject({ status: 422 });
    await settle();

    assert.equal(h.saveState.value, 'error');
    assert.equal(h.saveError.value, 'Not saved.');
    assert.equal(h.armed.value, true);
    assert.equal(h.autosave.scheduled(), false, 'a refused save is not repeated on a loop');

    void h.autosave.flush();
    await settle();
    assert.equal(h.sent.length, 2);
});

test('Back pressed before Next has saved: the step the operator is on is the one stored', async () => {
    const h = harness();

    h.autosave.queueStep('features');
    await settle();
    assert.deepEqual(h.sent.map((s) => s.step), ['features']);

    h.autosave.queueStep('foundation'); // the server still says foundation
    h.sent[0].resolve({ lock_version: 2, current_step: 'features' });
    await settle();
    await settle();

    assert.deepEqual(h.sent.map((s) => s.step), ['features', 'foundation']);
    assert.equal(h.sent[1].version, 2, 'the correction names the version the first save returned');
    h.sent[1].resolve({ lock_version: 3, current_step: 'foundation' });
    await settle();
    assert.equal(h.server.current_step, 'foundation');
    assert.equal(h.autosave.stepUnsent(), false);
});

test('a step queued behind an autosave and then undone is never sent', async () => {
    const h = harness();
    h.edit();
    h.tick();
    await settle();
    assert.deepEqual(h.sent.map((s) => s.step), [null]);

    h.autosave.queueStep('features'); // waits behind the edit's save
    h.autosave.queueStep('foundation'); // and is taken back before it goes
    h.sent[0].resolve({ lock_version: 2, current_step: 'foundation' });
    await settle();
    await settle();

    assert.deepEqual(h.sent.map((s) => s.step), [null], 'the server already holds foundation');
    assert.equal(h.autosave.stepUnsent(), false);
});

test('one PATCH at a time: an edit during a save goes after it, with the new version', async () => {
    const h = harness();
    h.edit();
    h.tick();
    await settle();

    h.edit();
    h.tick();
    await settle();
    assert.equal(h.sent.length, 1, 'the second waits for the first');

    h.sent[0].resolve({ lock_version: 2, current_step: 'foundation' });
    await settle();
    await settle();
    assert.equal(h.sent.length, 2);
    assert.equal(h.sent[1].version, 2);
});

test('nothing is sent for a draft that is no longer open', async () => {
    const h = harness();
    h.edit();
    h.tick();
    await settle();

    h.autosave.reset();
    h.sent[0].reject({ status: 409 });
    await settle();

    assert.equal(h.armed.value, true, 'an answer for the old draft changes nothing');
    assert.equal(h.conflicts(), 0);
});
