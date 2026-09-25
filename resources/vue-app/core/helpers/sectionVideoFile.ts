/**
 * Whether a file chosen for a page-builder `video` section can be uploaded.
 *
 * The server decides (App\Http\Requests\Concerns\ValidatesVideoSection: an MP4 by its
 * bytes, named `.mp4`, at most 25 MB). This is the same rule, told to the admin BEFORE an 18 MB upload
 * is sent and refused, and in words about the file rather than the generic 422 message.
 * The browser only knows the file's declared type, so a file that passes here can still
 * be refused by the server; the reverse cannot happen.
 */

/** 25 MB as the server counts it: Laravel's `max:25600` is in kilobytes of 1024 bytes. */
export const SECTION_VIDEO_MAX_BYTES = 25600 * 1024;

export const SECTION_VIDEO_MIME = 'video/mp4';

/**
 * The server also pins the NAME (`extensions:mp4`, any case): the stored file keeps it, and
 * the web server serves a file by its extension. A browser can report video/mp4 for a
 * `.m4v` or a renamed file, so the type alone would let through what the server refuses.
 */
const SECTION_VIDEO_NAME = /\.mp4$/i;

export function sectionVideoFileProblem(file: { name: string; type: string; size: number }): string | null {
    if (file.type !== SECTION_VIDEO_MIME || !SECTION_VIDEO_NAME.test(file.name)) {
        return 'Choose an MP4 video file (a name ending in .mp4). Other formats do not play in every browser.';
    }
    if (file.size > SECTION_VIDEO_MAX_BYTES) {
        const mb = (file.size / (1024 * 1024)).toFixed(1);
        return `This video is ${mb} MB. The limit is 25 MB: shorten it or export it at a lower bitrate.`;
    }
    return null;
}
