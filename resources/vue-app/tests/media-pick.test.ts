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

import { isVideoFile, pickerLimits, planMediaPick, videoLimitHint, type MediaLimits } from '../core/helpers/mediaPick.ts';

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
    assert.match(officeStory, /maxVideosTotalKb: feedStore\.feedMeta\?\.max_videos_total_kb/);
});
