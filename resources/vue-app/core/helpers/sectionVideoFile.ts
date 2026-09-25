/**
 * Whether a file chosen for a page-builder `video` section can be uploaded.
 *
 * The server decides (App\Http\Requests\Concerns\ValidatesVideoSection: an MP4 by its
 * bytes, at most 25 MB). This is the same rule, told to the admin BEFORE an 18 MB upload
 * is sent and refused, and in words about the file rather than the generic 422 message.
 * The browser only knows the file's declared type, so a file that passes here can still
 * be refused by the server; the reverse cannot happen.
 */

/** 25 MB as the server counts it: Laravel's `max:25600` is in kilobytes of 1024 bytes. */
export const SECTION_VIDEO_MAX_BYTES = 25600 * 1024;

export const SECTION_VIDEO_MIME = 'video/mp4';

export function sectionVideoFileProblem(file: { type: string; size: number }): string | null {
    if (file.type !== SECTION_VIDEO_MIME) {
        return 'Choose an MP4 video file. Other formats do not play in every browser.';
    }
    if (file.size > SECTION_VIDEO_MAX_BYTES) {
        const mb = (file.size / (1024 * 1024)).toFixed(1);
        return `This video is ${mb} MB. The limit is 25 MB: shorten it or export it at a lower bitrate.`;
    }
    return null;
}
