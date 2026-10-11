import { onBeforeUnmount, type Ref } from 'vue';

/** Timetable date boxes wait for typing to stop; Enter and blur commit immediately. */
export function useCommittedDate(draft: Ref<string>, commit: () => void) {
    let typing: ReturnType<typeof setTimeout> | undefined;
    const clear = () => { clearTimeout(typing); };
    const typed = () => {
        clear();
        typing = setTimeout(() => { if (draft.value >= '2000-01-01') commit(); }, 400);
    };
    const commitNow = () => { clear(); commit(); };
    onBeforeUnmount(clear);
    return { typed, commitNow, clear };
}
