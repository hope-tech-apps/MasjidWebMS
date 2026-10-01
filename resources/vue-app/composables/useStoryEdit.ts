import { ref } from 'vue';
import { apiErrorText } from '@/core/services/ApiErrors';
import { storyEditFields, type StoryDraft } from '@/core/helpers/storyEdit';

/**
 * One story list's "edit in place" state (W7-2a): which story is open, whether its save is
 * in flight, and why the last save failed, in words, shown beside the form.
 *
 * It makes no request itself. `save` is the screen's own client (the teacher's or the
 * office's) and resolves with the server's story, which `saved` puts back in the list; the
 * edit then closes. On a failure the form stays open with what was typed, so nothing is lost.
 */
export function useStoryEdit<T extends { id: number | string }>(
    save: (post: T, fields: { title: string; body: string }) => Promise<T>,
    saved: (updated: T) => void,
) {
    const editingId = ref<number | string | null>(null);
    const busy = ref(false);
    const error = ref('');

    const start = (post: T) => {
        editingId.value = post.id;
        error.value = '';
    };

    const cancel = () => {
        editingId.value = null;
        error.value = '';
    };

    const submit = async (post: T, draft: StoryDraft) => {
        busy.value = true;
        error.value = '';
        try {
            saved(await save(post, storyEditFields(draft)));
            editingId.value = null;
        } catch (e) {
            error.value = apiErrorText(e, 'That story could not be saved.');
        } finally {
            busy.value = false;
        }
    };

    return { editingId, busy, error, start, cancel, submit };
}
