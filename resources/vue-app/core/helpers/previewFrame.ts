/**
 * The admin side of the live-preview frame, as pure rules tests/preview-frame.test.ts
 * proves (docs/live-preview.md §4.5). LivePreviewPane.vue only wires them up.
 *
 *   PREVIEW_FRAME_SANDBOX  the frame may run scripts, keep its own storage, submit forms
 *                          and open new tabs — never navigate the admin tab. Saved page
 *                          content is written by client editors and previewed by owners
 *                          and SuperAdmins; a `target="_top"` link must not be able to
 *                          replace their tab. allow-same-origin is safe here because the
 *                          frame is on a different origin from the admin.
 *   isReadyFromFrame       a `ready` is believed only from this pane's own frame window,
 *                          at the preview origin the API named
 *   overridesMessage       the unsaved values as plain JSON (reactive proxies cannot be
 *                          structured-cloned), in the protocol's envelope
 *   postTargetFor          where unsaved values may be posted: exactly the preview origin,
 *                          never '*', so a frame that navigated elsewhere receives nothing
 */

export const PREVIEW_FRAME_SANDBOX = 'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox';

export const PREVIEW_MESSAGE_SOURCE = 'manara-preview';
export const ADMIN_MESSAGE_SOURCE = 'manara-admin';

export interface FrameMessage {
    origin: string;
    source: unknown;
    data: unknown;
}

export const isReadyFromFrame = (event: FrameMessage, frameWindow: unknown, previewOrigin: string): boolean => {
    if (!frameWindow || event.source !== frameWindow) return false;
    if (!previewOrigin || event.origin !== previewOrigin) return false;
    const data = event.data as { source?: unknown; v?: unknown; type?: unknown } | null;
    return !!data && data.source === PREVIEW_MESSAGE_SOURCE && data.v === 1 && data.type === 'ready';
};

export const overridesMessage = (overrides: unknown) => ({
    source: ADMIN_MESSAGE_SOURCE,
    v: 1 as const,
    type: 'overrides' as const,
    overrides: JSON.parse(JSON.stringify(overrides ?? {})),
});

/** The origin to post to, or null when there is no valid one (then nothing is posted). */
export const postTargetFor = (previewOrigin: string | null | undefined): string | null =>
    typeof previewOrigin === 'string' && /^https?:\/\/[a-z0-9.-]+(:\d{1,5})?$/.test(previewOrigin) ? previewOrigin : null;
