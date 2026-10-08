import { onBeforeUnmount, watch } from 'vue';

// Subscribers are registered during setup and removed at unmount. A completed
// save can notify a replacement view without reviving its disposed component.
const saveViews = new Set<{ enabled: () => boolean; owner: () => string; refresh: (operation: string) => void }>();

/** Accept only the latest read of each displayed data channel. OFF retains legacy sequencing. */
export function useToolResponseGuard(enabled: () => boolean, context: () => string) {
    let alive = true;
    let revision = 0;
    const stopSelections: (() => void)[] = [];
    const channels = new Map<string, { sequence: number; revision: number }>();
    watch(context, () => { ++revision; }, { flush: 'sync' });
    // Initial capability discovery must not discard the email's bootstrap Points read.
    watch(enabled, (_on, wasOn) => { if (wasOn) ++revision; }, { flush: 'sync' });
    onBeforeUnmount(() => { alive = false; ++revision; stopSelections.forEach(stop => stop()); channels.clear(); });
    const capture = (identity: () => unknown = () => null, channel = 'tool') => {
        const wasOn = enabled();
        // Legacy continuations may still fetch and apply data after unmount. They must
        // not allocate watchers or channels after their one cleanup has already run.
        if (!alive) return () => !wasOn && !enabled();
        let state = channels.get(channel);
        if (!state) {
            state = { sequence: 0, revision: 0 };
            channels.set(channel, state);
            const current = state;
            stopSelections.push(watch(identity, () => { ++current.revision; }, { flush: 'sync' }));
        }
        const token = ++state.sequence;
        const screen = revision;
        const selection = state.revision;
        const expected = identity();
        return () => (!wasOn && !enabled()) || (alive &&
            screen === revision && selection === state!.revision &&
            token === state!.sequence && identity() === expected);
    };
    // A save can invalidate a pre-save GET without registering another request.
    capture.invalidate = (channel: string) => {
        const state = alive && channels.get(channel);
        if (state) ++state.sequence;
    };
    return capture;
}

/**
 * A completed save reconciles its still-displayed owner, independently of its editor.
 * No save result is sequenced away. finish releases its watcher and returns whether
 * this operation still owns the busy flag (OFF always does). Call it in finally;
 * call saved after success to refresh any replacement view, and report errors
 * regardless of editor/owner acceptance. OFF retains the original success and error continuations.
 */
export function useToolSaveContext(enabled: () => boolean, owner: () => string, view: () => string = owner,
    refresh?: (operation: string) => void) {
    let alive = true;
    let revision = 0;
    const selections = new Set<() => void>();
    const busyOwners = new Map<string, number>();
    let operation = 0;
    const subscriber = refresh ? { enabled, owner, refresh } : null;
    if (subscriber) saveViews.add(subscriber);
    watch(view, () => { ++revision; }, { flush: 'sync' });
    watch(enabled, (_on, wasOn) => { if (wasOn) ++revision; }, { flush: 'sync' });
    onBeforeUnmount(() => { alive = false; selections.forEach(stop => stop()); selections.clear(); busyOwners.clear(); if (subscriber) saveViews.delete(subscriber); });
    return (identity: () => unknown = () => null, busy = 'save') => {
        const wasOn = enabled();
        const token = ++operation;
        if (alive) busyOwners.set(busy, token);
        const expectedOwner = owner();
        const expectedEditor = identity();
        const screen = revision;
        let changed = false;
        const stop = alive ? watch(identity, () => { changed = true; }, { flush: 'sync' }) : () => {};
        if (alive) selections.add(stop);
        const legacy = () => !wasOn && !enabled();
        const reconcile = () => legacy() || (alive && owner() === expectedOwner);
        return {
            reconcile,
            saved: () => {
                for (const current of saveViews) {
                    if (current !== subscriber && current.enabled() && current.owner() === expectedOwner) current.refresh(busy);
                }
            },
            editor: () => legacy() || (reconcile() && screen === revision && !changed && identity() === expectedEditor),
            finish: () => {
                stop(); selections.delete(stop);
                const ownsBusy = legacy() || !alive || busyOwners.get(busy) === token;
                if (busyOwners.get(busy) === token) busyOwners.delete(busy);
                return ownsBusy;
            },
        };
    };
}
