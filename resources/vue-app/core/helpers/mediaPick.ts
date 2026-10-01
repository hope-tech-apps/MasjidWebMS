/**
 * Which of the files a teacher just chose are kept, for a class story or a message.
 *
 * ONE definition for every picker (the shared GroupMediaPicker and the office's story
 * box), because the limits are the server's and a second copy is the one that stops
 * getting the fix. The server refuses what passes these, in the same sentences; these
 * exist so the refusal happens while the teacher is still choosing, not after a
 * 100MB upload.
 *
 * Four limits, each its own:
 *  - photos by count;
 *  - videos by count (three since 2026-10-01; it was one);
 *  - one video by size;
 *  - the videos of one post or message TOGETHER (`maxVideosTotalKb`). The count and
 *    the per-file size alone would allow a ~364MB request, which the servers refuse
 *    as a bare "upload failed"; the total keeps three clips inside what they accept.
 *
 * A limit of 0 or less means "not told" for the two sizes (the server still decides),
 * and "none allowed" for the two counts.
 */
export type PickedFile = { type?: string; size?: number; name?: string };

export type MediaLimits = {
    maxImages: number;
    maxVideos: number;
    /** One video, in KB. 0 = not told. */
    maxVideoKb?: number;
    /** The videos of one post or message together, in KB. 0 = no total. */
    maxVideosTotalKb?: number;
};

export type MediaPick<T> = { accepted: T[]; note: string };

export const isVideoFile = (file: PickedFile): boolean => (file.type || '').startsWith('video/');

const megabytes = (kb: number): number => Math.round(kb / 1024);

const videosWord = (count: number): string => (count === 1 ? 'video' : 'videos');

/**
 * Keep what fits beside `current`; say why anything was left out.
 *
 * Files are taken in the order chosen, so "the first three clips" is what stays.
 */
export function planMediaPick<T extends PickedFile>(current: T[], chosen: T[], limits: MediaLimits): MediaPick<T> {
    const heldVideos = current.filter(isVideoFile);

    let imageRoom = Math.max(0, limits.maxImages - (current.length - heldVideos.length));
    let videoRoom = Math.max(0, limits.maxVideos - heldVideos.length);

    const maxVideoBytes = (limits.maxVideoKb ?? 0) > 0 ? (limits.maxVideoKb as number) * 1024 : Infinity;
    const totalBytes = (limits.maxVideosTotalKb ?? 0) > 0 ? (limits.maxVideosTotalKb as number) * 1024 : Infinity;
    let videoBytes = heldVideos.reduce((sum, file) => sum + (file.size ?? 0), 0);

    const accepted: T[] = [];
    let droppedImages = 0;
    let droppedVideos = 0;
    let tooLarge = 0;
    let overTotal = 0;

    for (const file of chosen) {
        if (!isVideoFile(file)) {
            if (imageRoom > 0) { accepted.push(file); imageRoom--; } else { droppedImages++; }
            continue;
        }

        const size = file.size ?? 0;

        if (videoRoom <= 0) { droppedVideos++; continue; }
        if (size > maxVideoBytes) { tooLarge++; continue; }
        if (videoBytes + size > totalBytes) { overTotal++; continue; }

        accepted.push(file);
        videoRoom--;
        videoBytes += size;
    }

    const notes: string[] = [];

    if (droppedImages) {
        notes.push(`Up to ${limits.maxImages} photos at a time — the extra ${droppedImages} were not added.`);
    }
    if (droppedVideos) {
        notes.push(limits.maxVideos > 0
            ? `Up to ${limits.maxVideos} ${videosWord(limits.maxVideos)} at a time.`
            : 'Videos cannot be added here.');
    }
    if (tooLarge) {
        notes.push(`A video can be up to ${megabytes(limits.maxVideoKb as number)}MB — ${tooLarge === 1 ? 'one was' : `${tooLarge} were`} too large.`);
    }
    if (overTotal) {
        notes.push(`Videos sent together can add up to ${megabytes(limits.maxVideosTotalKb as number)}MB — send the ${overTotal === 1 ? 'other one' : 'others'} separately.`);
    }

    return { accepted, note: notes.join(' ') };
}

/** "3 videos up to 100MB each, 120MB together": the limit as a teacher reads it, or ''. */
export function videoLimitHint(limits: MediaLimits): string {
    if (limits.maxVideos <= 0) return '';

    const each = (limits.maxVideoKb ?? 0) > 0 ? ` up to ${megabytes(limits.maxVideoKb as number)}MB${limits.maxVideos === 1 ? '' : ' each'}` : '';
    // A total at or above count × size never binds, so it is not stated.
    const binds = (limits.maxVideosTotalKb ?? 0) > 0 && limits.maxVideos > 1
        && (limits.maxVideosTotalKb as number) < limits.maxVideos * (limits.maxVideoKb ?? Infinity);
    const together = binds ? `, ${megabytes(limits.maxVideosTotalKb as number)}MB together` : '';

    return `${limits.maxVideos} ${videosWord(limits.maxVideos)}${each}${together}`;
}

/** The server's `meta` on a posts or threads list, as far as the pickers read it. */
type MediaMeta = Record<string, unknown> | null | undefined;

/**
 * GroupMediaPicker's props from the server's `meta`, for `v-bind`.
 *
 * Only what the server actually said: a key it did not send is left out, so the
 * picker's own default stands for it (an older server, or a list that has not
 * loaded yet). `imagesKey` / `videosKey` differ between the two surfaces
 * (`max_images_per_post` / `max_images_per_message`, and the same for videos).
 */
export function pickerLimits(meta: MediaMeta, imagesKey: string, videosKey: string): Record<string, number | string> {
    const props: Record<string, number | string> = {};
    if (!meta) return props;

    const number = (key: string): number | null => {
        const value = meta[key];
        return typeof value === 'number' && Number.isFinite(value) ? value : null;
    };
    const list = (key: string): string | null => {
        const value = meta[key];
        return Array.isArray(value) && value.length ? value.join(',') : null;
    };

    const max = number(imagesKey);
    const maxVideos = number(videosKey);
    const maxVideoKb = number('max_video_size_kb');
    const maxVideosTotalKb = number('max_videos_total_kb');
    const accept = list('accepted_image_types');
    const videoAccept = list('accepted_video_types');

    if (max !== null && max > 0) props.max = max;
    if (maxVideos !== null) props.maxVideos = maxVideos;
    if (maxVideoKb !== null) props.maxVideoKb = maxVideoKb;
    if (maxVideosTotalKb !== null) props.maxVideosTotalKb = maxVideosTotalKb;
    if (accept !== null) props.accept = accept;
    if (videoAccept !== null) props.videoAccept = videoAccept;

    return props;
}
