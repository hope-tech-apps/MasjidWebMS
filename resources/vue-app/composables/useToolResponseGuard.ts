import { onBeforeUnmount, watch } from 'vue';

/** A response belongs to the screen that sent it. OFF retains legacy sequencing. */
export function useToolResponseGuard(enabled: () => boolean, context: () => string) {
    let alive = true;
    let revision = 0;
    const stopSelections: (() => void)[] = [];
    const channels = new Map<string, { sequence: number; revision: number }>();
    watch(context, () => { ++revision; }, { flush: 'sync' });
    // Initial capability discovery must not discard the email's bootstrap Points read.
    // Leaving ON invalidates its requests even if the capability is turned back on.
    watch(enabled, (_on, wasOn) => { if (wasOn) ++revision; }, { flush: 'sync' });
    onBeforeUnmount(() => { alive = false; ++revision; stopSelections.forEach(stop => stop()); });
    return (identity: () => unknown = () => null, channel = 'tool') => {
        let state = channels.get(channel);
        if (!state) {
            state = { sequence: 0, revision: 0 };
            channels.set(channel, state);
            const current = state;
            // These captures also run in event handlers, outside Vue's setup scope.
            stopSelections.push(watch(identity, () => { ++current.revision; }, { flush: 'sync' }));
        }
        const token = ++state.sequence;
        const screen = revision;
        const selection = state.revision;
        const expected = identity();
        const wasOn = enabled();
        return () => (!wasOn && !enabled()) || (alive &&
            screen === revision && selection === state!.revision &&
            token === state!.sequence && identity() === expected);
    };
}
