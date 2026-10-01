/**
 * Editing the words of a message already sent (W7, 2026-10-01).
 *
 * The rules live on the server (`GroupThreadsController::updateMessage`): only the
 * author, in an open conversation they can still read, the body only, and an empty
 * body only for a message that carries an attachment. This module is the screens'
 * half: whether the Save button should be on, and how the list takes the answer.
 * Nothing here decides authority. `can_edit` comes from the server's payload, and a
 * refusal that still arrives (a 403 after a roster change, a 422 on a closed
 * conversation) is shown in place.
 *
 * `.vue` files cannot be loaded by `node --test`, so the logic is here and the
 * wiring is pinned as source text (tests/edit-sent-message.test.ts).
 */

export type EditableMessage = {
    id: number;
    body?: string | null;
    can_edit?: boolean;
    edited_at?: string | null;
    attachments?: unknown[];
    media_withheld?: boolean;
};

/** One earlier version, as the office's `.../edits` route sends it. */
export type MessageEditRow = {
    id: number;
    previous_body: string;
    edited_by: string | null;
    replaced_at: string | null;
};

export const EDITED_LABEL = 'Edited';

/** True for a message that carries a photo or a video, even one this viewer may not see. */
export function messageHasMedia(message: Pick<EditableMessage, 'attachments' | 'media_withheld'>): boolean {
    return (message.attachments?.length ?? 0) > 0 || message.media_withheld === true;
}

/** One line ending: a message sent from a multipart form was stored with "\r\n", a textarea yields "\n". */
const lines = (text: string): string => text.replace(/\r\n?/g, '\n');

/**
 * The server compares trimmed text with one line ending, so an edit that only changes
 * surrounding whitespace, or "\r\n" for "\n", is not one.
 */
export function isUnchanged(draft: string, original: string | null | undefined): boolean {
    return lines(draft).trim() === lines(original ?? '').trim();
}

/**
 * Why this draft cannot be saved, or '' when it can. Mirrors the server only so the
 * author is told before the round trip; the server stays the authority.
 */
export function editProblem(
    draft: string,
    message: Pick<EditableMessage, 'body' | 'attachments' | 'media_withheld'>,
    maxLength = 0
): string {
    const text = draft.trim();

    if (text === '' && !messageHasMedia(message)) return 'Write a message.';
    if (maxLength > 0 && text.length > maxLength) {
        return `A message can be at most ${maxLength} characters.`;
    }

    return '';
}

/** Save is on for a valid draft that actually changes the words. */
export function canSaveEdit(
    draft: string,
    message: Pick<EditableMessage, 'body' | 'attachments' | 'media_withheld'>,
    maxLength = 0
): boolean {
    return editProblem(draft, message, maxLength) === '' && !isUnchanged(draft, message.body);
}

/** The list with `updated` in the place of the message that has its id; a new array, order kept. */
export function replaceMessage<T extends { id: number }>(list: T[], updated: T): T[] {
    return list.map((row) => (row.id === updated.id ? updated : row));
}

/**
 * Apply the server's answer to a message the screen already holds, in place, so
 * the bubble keeps its position and any list bound to it updates.
 */
export function applyEdited<T extends { id: number }>(target: T, updated: T): T {
    return Object.assign(target, updated);
}

/** "Original", then "Version 2", "Version 3": the earlier texts, oldest first, as the office reads them. */
export function versionLabel(index: number): string {
    return index === 0 ? 'Original' : `Version ${index + 1}`;
}
