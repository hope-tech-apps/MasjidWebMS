/**
 * What the broadcast composer sends (views/dashboard/broadcasts/broadcastPayload.ts).
 * The server validates again; these pin what the page asks for. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { broadcastFields, composerChannels, pushAudienceWarning, type BroadcastForm } from '../views/dashboard/broadcasts/broadcastPayload.ts';

const read = (rel: string) => readFileSync(new URL(`../${rel}`, import.meta.url), 'utf8');
/** Source with its comments taken out, for a "must not contain" check. */
const code = (source: string) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '').replace(/^\s*\/\/.*$/gm, '');

const form = (o: Partial<BroadcastForm> = {}): BroadcastForm => ({
    title: 'Fall festival', body: 'Saturday', link: '', channels: ['email'], audience: 'everyone',
    service_id: '', tag_id: '', starts_on: '', ends_on: '', scheduled_at: '', ...o,
});

test('a tag audience sends the chosen tag, and no other audience does', () => {
    const tagged = broadcastFields(form({ audience: 'tag', tag_id: 9 }), false);
    assert.deepEqual(tagged.filter(([k]) => k === 'audience' || k === 'tag_id'), [['audience', 'tag'], ['tag_id', '9']]);

    const everyone = broadcastFields(form({ tag_id: 9, service_id: 4 }), false);
    assert.equal(everyone.some(([k]) => k === 'tag_id' || k === 'service_id'), false);

    const service = broadcastFields(form({ audience: 'service', service_id: 4, tag_id: 9 }), false);
    assert.deepEqual(service.filter(([k]) => k.endsWith('_id')), [['service_id', '4']]);
});

test('channels travel as channels[], and the announcement dates only with an announcement', () => {
    const fields = broadcastFields(form({ channels: ['email', 'sms'], starts_on: '2026-10-01', ends_on: '2026-10-02' }), true);
    assert.deepEqual(fields.filter(([k]) => k === 'channels[]').map(([, v]) => v), ['email', 'sms']);
    assert.deepEqual(fields.filter(([k]) => k.endsWith('_on')), [['starts_on', '2026-10-01'], ['ends_on', '2026-10-02']]);
    assert.equal(broadcastFields(form({ starts_on: '2026-10-01' }), false).some(([k]) => k === 'starts_on'), false);
});

test('push to a tag or to chosen contacts is refused with a sentence; push to everyone is not', () => {
    assert.match(pushAudienceWarning('tag', ['email', 'push']), /Push cannot be sent to a tag/);
    assert.match(pushAudienceWarning('contacts', ['push']), /chosen contacts/);
    assert.equal(pushAudienceWarning('tag', ['email']), '');
    assert.equal(pushAudienceWarning('everyone', ['push']), '');
    assert.equal(pushAudienceWarning('service', ['push']), '');
});

test('no lobby-screen channel is offered; the feed hint names the TV only for an organisation that has one', () => {
    for (const hasTv of [false, true]) {
        assert.deepEqual(composerChannels(hasTv).map(c => c.value), ['announcement', 'push', 'email', 'sms']);
    }
    const feedHint = (hasTv: boolean) => composerChannels(hasTv).find(c => c.value === 'announcement')!.hint;
    assert.match(feedHint(true), /Your lobby TV shows it too/);
    assert.doesNotMatch(feedHint(false), /TV|screen/i);
    assert.match(feedHint(false), /Needs a picture and a date range/);
    assert.match(feedHint(true), /Needs a picture and a date range/);
});

test('the composer page draws that list by the tv_display grant, and neither broadcast page promises a screen', () => {
    const composer = code(read('views/dashboard/broadcasts/BroadcastComposerView.vue'));
    assert.match(composer, /composerChannels\(hasGrant\(masjidStore\.masjid, 'tv_display'\)\)/);
    assert.doesNotMatch(composer, /signage|lobby screen|TV board/i);
    // The list keeps its "Screen" label for a signage delivery that already exists.
    const list = code(read('views/dashboard/broadcasts/BroadcastsView.vue'));
    assert.match(list, /signage: 'Screen'/);
    assert.doesNotMatch(list, /lobby screen|TV board/i);
});
