/**
 * The video section editor's file check (core/helpers/sectionVideoFile.ts), which must
 * refuse exactly what the server refuses by type, name and size, so an admin hears about a
 * too-large or wrong file before uploading it. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SECTION_VIDEO_MAX_BYTES, SECTION_VIDEO_MIME, sectionVideoFileProblem } from '../core/helpers/sectionVideoFile.ts';

test('the limit is the server\'s max:25600 in bytes, and an MP4 at the limit is accepted', () => {
    assert.equal(SECTION_VIDEO_MAX_BYTES, 26_214_400);
    assert.equal(SECTION_VIDEO_MIME, 'video/mp4');
    assert.equal(sectionVideoFileProblem({ name: 'clip.mp4', type: 'video/mp4', size: SECTION_VIDEO_MAX_BYTES }), null);
    // The MEC home clip's web copy (VIDEO-SECTION-PLAN §3).
    assert.equal(sectionVideoFileProblem({ name: 'home-clip.mp4', type: 'video/mp4', size: 18_508_301 }), null);
});

test('one byte over the limit is refused, and the message gives the size', () => {
    const problem = sectionVideoFileProblem({ name: 'clip.mp4', type: 'video/mp4', size: SECTION_VIDEO_MAX_BYTES + 1 });
    assert.match(problem ?? '', /25\.0 MB/);
    assert.match(problem ?? '', /limit is 25 MB/);
});

test('anything but an MP4 is refused, whatever its size', () => {
    for (const type of ['video/quicktime', 'video/webm', 'image/jpeg', '']) {
        assert.match(sectionVideoFileProblem({ name: 'clip.mp4', type, size: 1024 }) ?? '', /MP4/, type);
    }
});

test('an MP4 whose name does not end in .mp4 is refused, as the server refuses it; the case does not matter', () => {
    for (const name of ['clip.html', 'clip.m4v', 'clip', 'clip.mp4.html']) {
        assert.match(sectionVideoFileProblem({ name, type: 'video/mp4', size: 1024 }) ?? '', /\.mp4/, name);
    }
    assert.equal(sectionVideoFileProblem({ name: 'CLIP.MP4', type: 'video/mp4', size: 1024 }), null);
});
