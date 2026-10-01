/**
 * Which chosen files a class story or a message keeps (mediaPick.ts): photos by count,
 * videos by count (three since 2026-10-01), one video by size, and the videos TOGETHER.
 *
 * `.vue` files cannot be loaded by `node --test`, so the wiring (every picker reads the
 * SERVER's limits, and both pickers use this one rule) is pinned as source text, the way
 * scheduled-send.test.ts does it.
 *
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

import { DEFAULT_POST_LIMITS, isVideoFile, pickerLimits, planMediaPick, videoLimitHint, type MediaLimits } from '../core/helpers/mediaPick.ts';
import { uploadErrorText } from '../core/services/ApiErrors.ts';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = (relative: string): string => readFileSync(path.join(appRoot, relative), 'utf8');

const MB = 1024 * 1024;
const photo = (name: string, size = 2 * MB) => ({ name, size, type: 'image/jpeg' });
const video = (name: string, size = 30 * MB) => ({ name, size, type: 'video/mp4' });

// The server's defaults: 8 photos, 3 videos, 100MB each, 120MB together.
const LIMITS: MediaLimits = { maxImages: 8, maxVideos: 3, maxVideoKb: 102400, maxVideosTotalKb: 122880 };

test('three ordinary clips and photos are all kept, with nothing to say', () => {
    const chosen = [video('a.mp4'), photo('1.jpg'), video('b.mp4'), video('c.mp4'), photo('2.jpg')];
    const { accepted, note } = planMediaPick([], chosen, LIMITS);

    assert.deepEqual(accepted, chosen);
    assert.equal(note, '');
});

test('a fourth video is left out, in order, and the sentence has its plural', () => {
    const { accepted, note } = planMediaPick([], [video('a'), video('b'), video('c'), video('d')], LIMITS);

    assert.deepEqual(accepted.map((f) => f.name), ['a', 'b', 'c']);
    assert.equal(note, 'Up to 3 videos at a time.');
});

test('a limit of one still reads "1 video"', () => {
    const { accepted, note } = planMediaPick([], [video('a'), video('b')], { ...LIMITS, maxVideos: 1 });

    assert.equal(accepted.length, 1);
    assert.equal(note, 'Up to 1 video at a time.');
});

test('videos already chosen count against the room and the total', () => {
    const held = [video('held-1', 50 * MB), video('held-2', 50 * MB)];

    // One slot left by count, but only 20MB left of the 120MB total.
    const tooBig = planMediaPick(held, [video('third', 30 * MB)], LIMITS);
    assert.deepEqual(tooBig.accepted, []);
    assert.equal(tooBig.note, 'Videos sent together can add up to 120MB — send the other one separately.');

    const fits = planMediaPick(held, [video('third', 20 * MB)], LIMITS);
    assert.equal(fits.accepted.length, 1);
    assert.equal(fits.note, '');
});

test('one clip over the per-video size is refused by name of the limit, and does not use a slot', () => {
    const { accepted, note } = planMediaPick([], [video('huge', 101 * MB), video('a'), video('b'), video('c')], LIMITS);

    assert.deepEqual(accepted.map((f) => f.name), ['a', 'b', 'c']);
    assert.equal(note, 'A video can be up to 100MB — one was too large.');
});

test('one full-size clip is still allowed on its own', () => {
    const { accepted, note } = planMediaPick([], [video('recital', 100 * MB)], LIMITS);

    assert.equal(accepted.length, 1);
    assert.equal(note, '');
});

test('a later, smaller clip still fits after one was left out for the total', () => {
    const { accepted, note } = planMediaPick([], [video('a', 70 * MB), video('b', 60 * MB), video('c', 40 * MB)], LIMITS);

    assert.deepEqual(accepted.map((f) => f.name), ['a', 'c']);
    assert.equal(note, 'Videos sent together can add up to 120MB — send the other one separately.');
});

test('photos keep their own count beside the videos', () => {
    const photos = Array.from({ length: 10 }, (_, i) => photo(`${i}.jpg`));
    const { accepted, note } = planMediaPick([], [...photos, video('a')], LIMITS);

    assert.equal(accepted.filter((f) => !isVideoFile(f)).length, 8);
    assert.equal(accepted.filter(isVideoFile).length, 1);
    assert.equal(note, 'Up to 8 photos at a time — the extra 2 were not added.');
});

test('sizes the server did not send are not enforced here; a count of zero refuses video', () => {
    const untold = planMediaPick([], [video('a', 500 * MB)], { maxImages: 8, maxVideos: 3 });
    assert.equal(untold.accepted.length, 1);

    const none = planMediaPick([], [video('a')], { ...LIMITS, maxVideos: 0 });
    assert.deepEqual(none.accepted, []);
    assert.equal(none.note, 'Videos cannot be added here.');
});

test('the hint states the total only when it binds', () => {
    assert.equal(videoLimitHint(LIMITS), '3 videos up to 100MB each, 120MB together');
    assert.equal(videoLimitHint({ ...LIMITS, maxVideos: 1 }), '1 video up to 100MB');
    assert.equal(videoLimitHint({ ...LIMITS, maxVideosTotalKb: 0 }), '3 videos up to 100MB each');
    assert.equal(videoLimitHint({ ...LIMITS, maxVideosTotalKb: 3 * 102400 }), '3 videos up to 100MB each');
    assert.equal(videoLimitHint({ ...LIMITS, maxVideos: 0 }), '');
});

test('picker props come from the server meta, and only what it sent', () => {
    const meta = {
        max_images_per_post: 8, max_videos_per_post: 3, max_video_size_kb: 102400, max_videos_total_kb: 122880,
        accepted_image_types: ['image/jpeg', 'image/png'], accepted_video_types: ['video/mp4'],
    };

    assert.deepEqual(pickerLimits(meta, 'max_images_per_post', 'max_videos_per_post'), {
        max: 8, maxVideos: 3, maxVideoKb: 102400, maxVideosTotalKb: 122880,
        accept: 'image/jpeg,image/png', videoAccept: 'video/mp4',
    });

    // A message list names its counts differently, and an older server sends no total.
    assert.deepEqual(
        pickerLimits({ max_images_per_message: 8, max_videos_per_message: 1 }, 'max_images_per_message', 'max_videos_per_message'),
        { max: 8, maxVideos: 1 },
    );

    // Video switched off on the server is passed on as 0, not dropped.
    assert.deepEqual(pickerLimits({ max_videos_per_post: 0 }, 'max_images_per_post', 'max_videos_per_post'), { maxVideos: 0 });
    assert.deepEqual(pickerLimits(null, 'max_images_per_post', 'max_videos_per_post'), {});
});

test('every picker reads the server limits and uses the one rule', () => {
    const teacher = source('views/teacher/TeacherClass.vue');
    // The teacher's three pickers were on the component's built-in defaults before.
    assert.equal((teacher.match(/<GroupMediaPicker[^>]*v-bind="storyMedia"/g) ?? []).length, 1);
    assert.equal((teacher.match(/<GroupMediaPicker[^>]*v-bind="messageMedia"/g) ?? []).length, 2);
    assert.equal((teacher.match(/<GroupMediaPicker/g) ?? []).length, 3, 'a new picker must be given the server limits too');
    assert.match(teacher, /storyMedia\.value = pickerLimits\(res\.data\?\.meta, 'max_images_per_post', 'max_videos_per_post'\)/);
    assert.match(teacher, /messageMedia\.value = pickerLimits\(res\.data\?\.meta, 'max_images_per_message', 'max_videos_per_message'\)/);

    const picker = source('components/partials/GroupMediaPicker.vue');
    assert.match(picker, /planMediaPick\(props\.modelValue, chosen,/);
    assert.match(picker, /maxVideosTotalKb: props\.maxVideosTotalKb/);

    const officeThreads = source('views/dashboard/groups/GroupThreadsTab.vue');
    assert.equal((officeThreads.match(/:max-videos-total-kb="maxVideosTotalKb"/g) ?? []).length, 2);
    assert.match(officeThreads, /threadsMeta\?\.max_videos_total_kb/);

    const officeStory = source('views/dashboard/groups/GroupStoryTab.vue');
    assert.match(officeStory, /planMediaPick<File>\(\[\], picked, mediaLimits\.value\)/);
    assert.match(officeStory, /maxVideosTotalKb: meta\.max_videos_total_kb/);
});

test('a clip the browser gives no type is still a video, by its extension', () => {
    assert.equal(isVideoFile({ name: 'recital.MOV', type: '', size: 1 }), true);
    assert.equal(isVideoFile({ name: 'clip.m4v', type: '', size: 1 }), true);
    assert.equal(isVideoFile({ name: 'photo.jpg', type: '', size: 1 }), false);
    // A stated type wins over the name; a generic one says nothing, so the name decides.
    assert.equal(isVideoFile({ name: 'odd.mov', type: 'image/jpeg', size: 1 }), false);
    assert.equal(isVideoFile({ name: 'clip.mov', type: 'application/octet-stream', size: 1 }), true);
    assert.equal(isVideoFile({ name: 'scan.pdf', type: 'application/octet-stream', size: 1 }), false);

    // So it meets the video limits instead of slipping through as a photo.
    const untyped = { name: 'big.mov', type: '', size: 101 * MB };
    assert.deepEqual(planMediaPick([], [untyped], LIMITS).accepted, []);
});

test('one rule splits the list into the two bags', () => {
    for (const file of ['views/teacher/TeacherClass.vue', 'stores/masjid/groupFeedStore.ts', 'stores/masjid/groupThreadsStore.ts']) {
        const text = source(file);
        assert.match(text, /isVideoFile\(file\) \?/, `${file} splits by isVideoFile`);
        assert.doesNotMatch(text, /\(file\.type \|\| ''\)\.startsWith\('video\/'\) \?/, `${file} has no second copy of the rule`);
    }
});

test('a 413 is answered in one sentence that names what is being sent', () => {
    const tooLarge = { response: { status: 413, data: '<html>413 Request Entity Too Large</html>' }, message: 'Request failed with status code 413' };

    assert.equal(
        uploadErrorText(tooLarge, 'The post could not be published.'),
        'That is too large to send together. Send fewer photos or videos at once, and the rest in another post.',
    );
    assert.equal(
        uploadErrorText(tooLarge, 'Your reply could not be sent.', 'message'),
        'That is too large to send together. Send fewer photos or videos at once, and the rest in another message.',
    );

    // Anything else is the API's own words, or the fallback.
    const refused = { response: { status: 422, data: { status: 'failed', data: { videos: ['A post may carry at most 3 videos.'] } } } };
    assert.equal(uploadErrorText(refused, 'fallback'), 'A post may carry at most 3 videos.');
    assert.equal(uploadErrorText({}, 'The post could not be published.'), 'The post could not be published.');
});

test('every upload screen uses that sentence, with the right noun', () => {
    const teacher = source('views/teacher/TeacherClass.vue');
    assert.match(teacher, /photoErrorText\(e, 'The post could not be published\.'\)/);
    assert.match(teacher, /photoErrorText\(e, 'That message could not be sent\.', 'message'\)/);
    assert.match(teacher, /photoErrorText\(e, 'Your reply could not be sent\.', 'message'\)/);

    assert.match(source('views/dashboard/groups/GroupStoryTab.vue'), /uploadErrorText\(error, 'Failed to publish the post\.'\)/);

    // BOTH office conversation uploads: opening one with media, and a reply.
    const officeThreads = source('views/dashboard/groups/GroupThreadsTab.vue');
    assert.match(officeThreads, /uploadErrorText\(error, 'Failed to open the conversation\.', 'message'\)/);
    assert.match(officeThreads, /uploadErrorText\(error, 'Failed to send the message\.', 'message'\)/);
    assert.equal((officeThreads.match(/uploadErrorText\(/g) ?? []).length, 2);
});

test('the office story box always has limits: the shipped defaults until the server sends its own', () => {
    const officeStory = source('views/dashboard/groups/GroupStoryTab.vue');

    // An administrator off the class roster is refused the feed READ, so `meta` never
    // arrives; with no limit at all, an oversized pick would upload and end in a 413.
    assert.match(officeStory, /if \(!meta\) return DEFAULT_POST_LIMITS;/);
    assert.match(officeStory, /planMediaPick<File>\(\[\], picked, mediaLimits\.value\)/);
    assert.match(officeStory, /\(meta\.max_images_per_post \?\? 0\) > 0 \? meta\.max_images_per_post : Infinity/);
    // What was picked on the stand-in is held to the real limits when they arrive.
    assert.match(officeStory, /watch\(mediaLimits, \(limits\) => \{[\s\S]*?planMediaPick<File>\(\[\], chosenFiles\.value, limits\)/);
    // The hint still explains the videos when photos have no limit.
    assert.doesNotMatch(officeStory, /if \(!meta \|\| !meta\.max_images_per_post\) return '';/);

    // The stand-in is the server's shipped defaults (config/groups.php).
    assert.deepEqual({ ...DEFAULT_POST_LIMITS }, { maxImages: 8, maxVideos: 3, maxVideoKb: 102400, maxVideosTotalKb: 122880 });
    const fourBig = [video('a', 90 * MB), video('b', 90 * MB), video('c', 90 * MB), video('d', 90 * MB)];
    assert.equal(planMediaPick([], fourBig, DEFAULT_POST_LIMITS).accepted.length, 1);
});
