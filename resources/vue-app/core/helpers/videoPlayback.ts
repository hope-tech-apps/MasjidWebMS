import { onBeforeUnmount, ref, type Ref } from 'vue';

/**
 * Playing a group video, from whichever realm is asking.
 *
 * WHY THIS IS NOT THE SAME AS SHOWING A PHOTO. A class photo is fetched as
 * BYTES with the caller's bearer token and shown through an object URL; that is
 * what keeps a private image off any URL that would work on its own. A video
 * cannot go that way twice over: a <video> element issues its own ranged
 * requests and cannot be given an Authorization header, and buffering a 100MB
 * file before the first frame is not playback.
 *
 * So the server mints a TICKET — a short-lived, viewer-bound, relative signed
 * URL whose handler re-resolves the whole ownership chain and re-asks consent on
 * every range. This asks for one, and asks again ONCE if the element reports an
 * error, which is what an expired ticket looks like from here (a parent who left
 * the tab open through lunch and then pressed play).
 *
 * `poster` is the realm's authenticated POST — admin, teacher or family — so
 * this file knows nothing about which token is in play.
 */
export interface VideoPlayback {
    /** The signed URL to hand to <video src>, or '' while it is being fetched. */
    src: Ref<string>;
    /** True once a ticket could not be obtained, or playback failed twice. */
    failed: Ref<boolean>;
    /** Wire to the element's @error: re-mints once, then gives up. */
    onError: () => void;
}

/** A video, by the only discriminator the attachment tables have. */
export const isVideoAttachment = (attachment: any): boolean =>
    attachment?.is_video === true || String(attachment?.mime_type ?? '').startsWith('video/');

export function useVideoPlayback(
    poster: (path: string) => Promise<any>,
    ticketPath: string | null | undefined,
): VideoPlayback {
    const src = ref('');
    const failed = ref(false);

    // One retry, not a loop. A ticket that has expired mints again and plays; a
    // consent that was withdrawn mints a 403 and must NOT be retried forever
    // against the server.
    let retried = false;
    let disposed = false;

    const mint = async () => {
        if (!ticketPath) {
            failed.value = true;
            return;
        }

        try {
            const res = await poster(ticketPath);
            const url = res?.data?.data?.url;

            if (disposed) return;

            if (typeof url === 'string' && url) {
                src.value = url;
                failed.value = false;
            } else {
                failed.value = true;
            }
        } catch {
            if (!disposed) failed.value = true;
        }
    };

    const onError = () => {
        if (retried || !src.value) {
            failed.value = true;
            return;
        }

        retried = true;
        src.value = '';
        void mint();
    };

    onBeforeUnmount(() => {
        disposed = true;
    });

    void mint();

    return { src, failed, onError };
}
