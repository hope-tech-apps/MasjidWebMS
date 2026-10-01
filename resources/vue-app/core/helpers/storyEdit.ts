/**
 * Editing a class story AFTER it is sent (W7-2a): the rules the two staff screens and the
 * family portal share. Plain functions, so `node --test` can pin them (`.vue` files cannot
 * be loaded there).
 *
 * The server decides who may edit (`can_edit`), stamps `edited_at` only when a story that
 * was already out really changed, and sends nothing to anyone. This file draws from those
 * two fields and never infers either one.
 */

/** What the edit form holds: the title (may be blank) and the text. */
export type StoryDraft = { heading: string; body: string };

/** The parts of a story payload this file reads. Both are absent on an older server. */
export type EditableStory = {
    title?: string | null;
    body?: string | null;
    can_edit?: boolean;
    edited_at?: string | null;
};

/** The Edit control is drawn only when the server said this caller's PUT would be allowed. */
export const canEditStory = (post: EditableStory | null | undefined): boolean => post?.can_edit === true;

/** The draft as it opens: what the story says now. */
export const draftOf = (post: EditableStory): StoryDraft => ({
    heading: post.title ?? '',
    body: post.body ?? '',
});

/** The request fields: title and text ONLY. Attachments are not edited here. */
export const storyEditFields = (draft: StoryDraft): { title: string; body: string } => ({
    title: draft.heading.trim(),
    body: draft.body.trim(),
});

/** Has the draft moved away from the story? A save that changes nothing is not offered. */
export const storyEditChanged = (draft: StoryDraft, post: EditableStory): boolean => {
    const now = storyEditFields(draft);

    return now.title !== (post.title ?? '').trim() || now.body !== (post.body ?? '').trim();
};

/** May the form be submitted: there is text, and something changed. */
export const storyEditReady = (draft: StoryDraft, post: EditableStory): boolean =>
    storyEditFields(draft).body !== '' && storyEditChanged(draft, post);

/**
 * The "Edited" marker beside the date: the word, and the time as its tooltip. Null for a
 * story never edited (or an unreadable time), so no marker is drawn for it.
 */
export const editedMarker = (
    editedAt: string | null | undefined,
    format: (iso: string) => string,
): { text: string; title: string } | null => {
    if (!editedAt || Number.isNaN(new Date(editedAt).getTime())) return null;

    return { text: 'Edited', title: `Edited ${format(editedAt)}` };
};

/**
 * The family translation cache key of one field of a story. `edited_at` is part of it, so a
 * story re-fetched after an edit is a NEW key and is translated again: the map holds any key
 * it already has, and a key made of the id alone would keep the old translation over the
 * new words until the page was reloaded.
 */
export const postTranslationKey = (
    post: { id: number | string; edited_at?: string | null },
    field: 'title' | 'body',
): string => `post:${post.id}:${field}:${post.edited_at ?? ''}`;
