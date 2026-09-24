/**
 * Step 3's pure rules (core/studio/provision.ts): the gate that keeps Provision
 * disabled (R27 and the BYO credentials), the body that carries the credentials
 * and nothing else (R7), how the answer is read (a 201 without
 * `capabilities_applied` is not success; a 409 is the organisation that
 * exists, or a draft that changed; "not created" only on the controller's
 * word), when an invitation may be called sent, the order the store must keep
 * (flush, check, post), iqama's truth, and where focus goes.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    byoPlatforms,
    clearSecrets,
    clearsSecrets,
    emptySecrets,
    featureSummary,
    generateBlockers,
    inviteeOf,
    inviteOutcome,
    iqamaBlockers,
    iqamaStatus,
    outcomeFocusId,
    provisionBody,
    readProvisionOutcome,
    saveThenPost,
    unsavedReason,
    webGateBlockers,
} from '../core/studio/provision.ts';
import type { ProvisionOutcome, SaveSnapshot } from '../core/studio/provision.ts';
import { autosaveBody, normaliseAnswers, STUDIO_SECTIONS } from '../core/studio/draftAnswers.ts';

const LOGO = 'A website needs the client\'s logo. Upload it in Foundation.';
const SLUG = 'A website needs a subdomain. Choose one in Foundation.';
const LAYOUT = 'A website needs an approved layout. Approve one in Layout.';

/** A draft ready for Generate: web, a subdomain, an approved layout, a feature map. */
const ready = (platforms: string[] = ['ios', 'web']) => normaliseAnswers({
    identity: { org_type: 'school', name: 'Al-Noor Academy', slug: 'alnoor', admin: { email: 'office@alnoor.example' } },
    brand: { primary_color: '#0a3d62', secondary_color: '#1e272e', accent_color: '#f6b93b', background_color: '#ffffff' },
    features: { capabilities: { events: true, donations: false } },
    layout: { preset: 'school_classic', approved_at: '2026-09-24T10:00:00.000Z' },
    platforms: { platforms, apps: { ios: { account_mode: 'managed' }, web: { account_mode: 'managed' } } },
});

const P8 = '-----BEGIN PRIVATE KEY-----\nMIGT\n-----END PRIVATE KEY-----';

const filledSecrets = () => {
    const secrets = emptySecrets();
    secrets.ios.asc_key_p8 = `  ${P8}\n`;
    secrets.ios.asc_key_id = ' KEY123 ';
    secrets.ios.asc_issuer_id = 'issuer-1';
    secrets.android.play_service_account_json = '{"type":"service_account"}';
    return secrets;
};

test('a ready web draft may be provisioned', () => {
    assert.deepEqual(generateBlockers(ready(), true, emptySecrets()), []);
});

test('the web gate needs a logo, a subdomain and an approved layout, each said in the server\'s words', () => {
    const noLogo = ready();
    assert.deepEqual(webGateBlockers(noLogo, false), [LOGO]);

    const noSlug = ready();
    noSlug.identity.slug = '  ';
    assert.deepEqual(webGateBlockers(noSlug, true), [SLUG]);

    const chosenNotApproved = ready();
    chosenNotApproved.layout.approved_at = null;
    assert.deepEqual(webGateBlockers(chosenNotApproved, true), [LAYOUT]);

    const noPreset = ready();
    noPreset.layout.preset = null;
    assert.deepEqual(generateBlockers(noPreset, true, emptySecrets()), [LAYOUT]);
});

test('without web, none of the three is needed', () => {
    const answers = ready(['ios', 'android']);
    answers.identity.slug = null;
    answers.layout = {};

    assert.deepEqual(webGateBlockers(answers, false), []);
    assert.deepEqual(generateBlockers(answers, false, emptySecrets()), []);
});

test('no feature map blocks: every switch would start at its default unseen', () => {
    const answers = ready();
    answers.features = {};
    assert.equal(generateBlockers(answers, true, emptySecrets()).length, 1);
    assert.match(generateBlockers(answers, true, emptySecrets())[0], /feature choices/);
});

test('a BYO platform needs its credentials; a managed or unselected one never asks', () => {
    const answers = ready(['ios', 'android', 'web']);
    answers.platforms.apps = { ios: { account_mode: 'byo' }, android: { account_mode: 'byo' }, web: { account_mode: 'managed' } };

    assert.deepEqual(byoPlatforms(answers), ['ios', 'android']);
    assert.equal(generateBlockers(answers, true, emptySecrets()).length, 4);
    assert.deepEqual(generateBlockers(answers, true, filledSecrets()), []);

    const badJson = filledSecrets();
    badJson.android.play_service_account_json = '{ not json';
    assert.deepEqual(generateBlockers(answers, true, badJson), ['The Google Play service-account JSON does not parse as JSON.']);

    // Android set to BYO but no longer selected: nothing is asked of it.
    answers.platforms.platforms = ['ios', 'web'];
    assert.deepEqual(byoPlatforms(answers), ['ios']);
});

test('the body carries only the selected BYO platforms\' credentials, trimmed', () => {
    const answers = ready(['ios', 'android', 'web']);
    answers.platforms.apps = { ios: { account_mode: 'byo' }, android: { account_mode: 'managed' } };

    assert.deepEqual(provisionBody(answers, filledSecrets()), {
        secrets: { ios: { asc_key_p8: P8, asc_key_id: 'KEY123', asc_issuer_id: 'issuer-1' } },
    });

    // All managed: the body is empty, whatever was typed.
    assert.deepEqual(provisionBody(ready(), filledSecrets()), {});
});

test('the body names the version of the draft reviewed, beside any credentials', () => {
    assert.deepEqual(provisionBody(ready(), filledSecrets(), 7), { lock_version: 7 });
    assert.deepEqual(provisionBody(ready(), filledSecrets(), 0), { lock_version: 0 }, 'version 0 is a version');

    const answers = ready(['android', 'web']);
    answers.platforms.apps = { android: { account_mode: 'byo' } };
    assert.deepEqual(provisionBody(answers, filledSecrets(), 3), {
        lock_version: 3,
        secrets: { android: { play_service_account_json: '{"type":"service_account"}' } },
    });
});

test('the credentials never reach the autosave body, and are blanked after use', () => {
    const answers = ready(['ios', 'web']);
    answers.platforms.apps = { ios: { account_mode: 'byo' } };
    const secrets = filledSecrets();
    provisionBody(answers, secrets);

    const saved = JSON.stringify(autosaveBody(1, answers, STUDIO_SECTIONS, 'generate'));
    for (const value of ['KEY123', 'issuer-1', 'PRIVATE KEY', 'service_account']) {
        assert.equal(saved.includes(value), false, `the autosave body carries ${value}`);
    }

    clearSecrets(secrets);
    assert.deepEqual(secrets, emptySecrets());
});

const created = (extra: Record<string, unknown> = {}) => ({
    status: 'success',
    data: {
        masjid_id: 42,
        masjid: { id: 42, name: 'Al-Noor Academy' },
        capabilities_applied: { changed: [{ key: 'donations', enabled: false }], unchanged: ['events'] },
        after_commit: { invites_sent: 1, invites_failed: 0, warnings: [] },
        ...extra,
    },
});

const INVITEE = { email: 'office@alnoor.example', existingUserId: null };

test('a 201 with capabilities_applied is created, carrying who was invited', () => {
    const outcome = readProvisionOutcome(201, created(), INVITEE);
    assert.equal(outcome.kind, 'created');
    assert.equal(outcome.kind === 'created' && outcome.result.masjid_id, 42);
    assert.deepEqual(outcome.kind === 'created' && outcome.invitee, INVITEE);
});

test('a 201 without capabilities_applied is an error naming the organisation, never success', () => {
    const body = created();
    delete (body.data as Record<string, unknown>).capabilities_applied;

    const outcome = readProvisionOutcome(201, body);
    assert.equal(outcome.kind, 'unconfirmed');
    assert.equal(outcome.kind === 'unconfirmed' && outcome.masjidId, 42);

    assert.equal(readProvisionOutcome(201, created({ capabilities_applied: null })).kind, 'unconfirmed');
});

test('a 409 is the organisation that already exists', () => {
    assert.deepEqual(
        readProvisionOutcome(409, { status: 'conflict', data: { draft_id: 7, provisioned_masjid_id: 42 } }),
        { kind: 'conflict', masjidId: 42 },
    );
});

test('a 409 with no organisation is a draft that changed since it was reviewed, in the server\'s words', () => {
    const message = 'This draft changed while it was being provisioned, so nothing was created. Review the latest answers and press Provision again.';
    assert.deepEqual(
        readProvisionOutcome(409, { status: 'conflict', message, data: { draft_id: 7, provisioned_masjid_id: null } }),
        { kind: 'changed', message },
    );
    assert.equal(readProvisionOutcome(409, { status: 'conflict', data: { draft_id: 7, provisioned_masjid_id: null } }).kind, 'changed');
});

test('a 422 lists every message of the legacy envelope once', () => {
    const outcome = readProvisionOutcome(422, {
        status: 'failed',
        data: { logo: [LOGO], 'brand.primary_color': ['Choose this colour as #RRGGBB.'], slug: [LOGO] },
    });
    assert.deepEqual(outcome, { kind: 'invalid', messages: [LOGO, 'Choose this colour as #RRGGBB.'] });
});

test('only the controller\'s own 500 says nothing was created; a lost or unexplained answer is unknown', () => {
    const failed = readProvisionOutcome(500, { status: 'error', data: 'Something went wrong.' });
    assert.deepEqual(failed, { kind: 'failed', message: 'The organisation was not created. Something went wrong.' });

    const lost = readProvisionOutcome(undefined, undefined);
    assert.equal(lost.kind, 'unknown');
    assert.match(lost.kind === 'unknown' ? lost.message : '', /No answer came back.*if the organisation was created, the server says so/);

    // A proxy's timeout page, and Laravel's own 500 for a failure after the commit.
    for (const [status, body] of [[504, '<html>Gateway Time-out</html>'], [502, undefined], [500, { message: 'Server Error' }]] as const) {
        const outcome = readProvisionOutcome(status, body);
        assert.equal(outcome.kind, 'unknown', `${status}`);
        assert.doesNotMatch(outcome.kind === 'unknown' ? outcome.message : '', /was not created/, `${status}`);
        assert.match(outcome.kind === 'unknown' ? outcome.message : '', new RegExp(`HTTP ${status}.*Press Provision again`));
    }

    assert.deepEqual(readProvisionOutcome(404, { status: 'error', message: 'Not found' }),
        { kind: 'failed', message: 'This draft no longer exists: it was discarded, so nothing was created.' });
});

test('an invitation is called sent only when one went and none failed', () => {
    const invitee = inviteeOf(ready());
    assert.deepEqual(invitee, INVITEE);

    assert.deepEqual(inviteOutcome({ invites_sent: 1, invites_failed: 0, warnings: [] }, invitee),
        { sent: true, text: 'Invitation sent to office@alnoor.example.' });

    assert.deepEqual(inviteOutcome({ invites_sent: 0, invites_failed: 1, warnings: ['x'] }, invitee),
        { sent: false, text: 'The invitation was not sent.' });

    assert.equal(inviteOutcome({ invites_sent: 1, invites_failed: 1, warnings: [] }, invitee).sent, false);
    assert.equal(inviteOutcome(undefined, invitee).sent, false);

    const existing = ready();
    existing.identity.user_id = 9;
    assert.deepEqual(inviteOutcome({ invites_sent: 0, invites_failed: 0, warnings: [] }, inviteeOf(existing)),
        { sent: false, text: 'No invitation was sent: an existing account was made the administrator.' });
});

test('the invitation names who the draft held when Provision was pressed, not what the answers say later', () => {
    const answers = ready();
    const outcome = readProvisionOutcome(201, created(), inviteeOf(answers));
    answers.identity.admin = { email: 'changed-later@alnoor.example' };

    assert.equal(outcome.kind, 'created');
    if (outcome.kind === 'created') {
        assert.equal(inviteOutcome(outcome.result.after_commit, outcome.invitee).text, 'Invitation sent to office@alnoor.example.');
    }
});

const IQAMA_SENTENCE = 'The iqama times are incomplete: Dhuhr, Asr, Maghrib, Isha are missing. Enter all five in Foundation, or tick "Client has not given iqama times".';

test('iqama is shown only with all five times; some is a blocker in the server\'s words, none is hidden', () => {
    const masjid = (prayer: Record<string, unknown>) => normaliseAnswers({ identity: { org_type: 'masjid' }, prayer });
    const five = { fajr: 20, dhuhr: 0, asr: 10, maghrib: 5, isha: 15 };

    assert.deepEqual(iqamaStatus(masjid({}), true), { state: 'none' });
    assert.deepEqual(iqamaStatus(masjid({ iqama: { fajr: null } }), true), { state: 'none' });
    assert.deepEqual(iqamaStatus(masjid({ iqama: five }), true), { state: 'given' }, 'a zero is a time');
    assert.deepEqual(iqamaStatus(masjid({ iqama: five, iqama_given: false }), true), { state: 'not_given' });
    assert.deepEqual(iqamaStatus(masjid({ iqama: five }), false), { state: 'not_asked' });
    assert.deepEqual(iqamaStatus(masjid({ iqama: { fajr: 25, isha: 10 } }), true), { state: 'partial', missing: ['Dhuhr', 'Asr', 'Maghrib'] });

    assert.deepEqual(iqamaBlockers(masjid({ iqama: { fajr: 25 } }), true), [IQAMA_SENTENCE]);
    assert.deepEqual(iqamaBlockers(masjid({ iqama: { fajr: 25, dhuhr: 1, asr: 1, maghrib: 1 } }), true),
        ['The iqama times are incomplete: Isha is missing. Enter all five in Foundation, or tick "Client has not given iqama times".']);
    for (const prayer of [{}, { iqama: five }, { iqama: { fajr: 25 }, iqama_given: false }]) {
        assert.deepEqual(iqamaBlockers(masjid(prayer), true), []);
    }
    assert.deepEqual(iqamaBlockers(masjid({ iqama: { fajr: 25 } }), false), [], 'a school is never asked');
});

test('the credentials are cleared once an organisation exists, and kept for a retry otherwise', () => {
    const exists: ProvisionOutcome[] = [
        { kind: 'created', result: created().data as never, invitee: INVITEE },
        { kind: 'unconfirmed', masjidId: 42, message: 'x' },
        { kind: 'conflict', masjidId: 42 },
    ];
    for (const outcome of exists) assert.equal(clearsSecrets(outcome), true, outcome.kind);

    const retry: (ProvisionOutcome | null)[] = [
        { kind: 'invalid', messages: ['x'] },
        { kind: 'failed', message: 'x' },
        { kind: 'unknown', message: 'x' },
        { kind: 'changed', message: 'x' },
        null,
    ];
    for (const outcome of retry) assert.equal(clearsSecrets(outcome), false, outcome?.kind ?? 'null');
});

const savedCleanly: SaveSnapshot = { saveState: 'saved', unsaved: 0, conflictMessage: null, saveError: null };

test('the answers are saved only when the autosave neither failed nor conflicted and nothing is left unsent', () => {
    assert.equal(unsavedReason(savedCleanly), null);
    assert.equal(unsavedReason({ ...savedCleanly, saveState: 'idle' }), null);
    assert.match(unsavedReason({ ...savedCleanly, unsaved: 1 }) ?? '', /^The latest answers are not saved, so nothing was created\.$/);
    assert.equal(unsavedReason({ ...savedCleanly, saveState: 'conflict', conflictMessage: 'Saved in another tab.' }),
        'The latest answers are not saved, so nothing was created. Saved in another tab.');
    assert.equal(unsavedReason({ ...savedCleanly, saveState: 'error', saveError: 'Network down.' }),
        'The latest answers are not saved, so nothing was created. Network down.');
});

test('the store\'s order: flush, then check the save, then post; and no post when the save did not land', async () => {
    const run = async (save: SaveSnapshot, open = true) => {
        const calls: string[] = [];
        const result = await saveThenPost({
            flush: async () => { calls.push('flush'); },
            stillOpen: () => { calls.push('open?'); return open; },
            save: () => { calls.push('save?'); return save; },
            post: async () => { calls.push('post'); return 'answer'; },
        });
        return { calls, result };
    };

    const ok = await run(savedCleanly);
    assert.deepEqual(ok.calls, ['flush', 'open?', 'save?', 'post']);
    assert.deepEqual(ok.result, { kind: 'posted', answer: 'answer' });

    for (const save of [
        { ...savedCleanly, saveState: 'conflict' as const, conflictMessage: 'Saved in another tab.' },
        { ...savedCleanly, saveState: 'error' as const },
        { ...savedCleanly, unsaved: 2 },
    ]) {
        const refused = await run(save);
        assert.deepEqual(refused.calls, ['flush', 'open?', 'save?'], `no POST for ${JSON.stringify(save)}`);
        assert.equal(refused.result.kind, 'unsaved');
    }

    const closed = await run(savedCleanly, false);
    assert.deepEqual(closed.calls, ['flush', 'open?']);
    assert.deepEqual(closed.result, { kind: 'closed' });

    // The save is read after the flush has finished, not before.
    let flushed = false;
    const late = await saveThenPost({
        flush: async () => { await Promise.resolve(); flushed = true; },
        stillOpen: () => true,
        save: () => (flushed ? savedCleanly : { ...savedCleanly, unsaved: 1 }),
        post: async () => 'answer',
    });
    assert.equal(late.kind, 'posted');
});

test('the answer takes focus: the Created or Already provisioned heading, otherwise the outcome\'s alert', () => {
    assert.equal(outcomeFocusId({ kind: 'created', result: created().data as never, invitee: INVITEE }), 'studio-panel-created');
    assert.equal(outcomeFocusId({ kind: 'conflict', masjidId: 42 }), 'studio-panel-already-provisioned');
    for (const kind of ['unconfirmed', 'changed', 'invalid', 'failed', 'unknown'] as const) {
        const outcome = (kind === 'invalid' ? { kind, messages: [] } : kind === 'unconfirmed' ? { kind, masjidId: 1, message: '' } : { kind, message: '' }) as ProvisionOutcome;
        assert.equal(outcomeFocusId(outcome), 'studio-generate-outcome', kind);
    }
    assert.equal(outcomeFocusId(null), null);
});

test('the review counts the switches on and the departures from the catalogue defaults', () => {
    const catalogue = {
        org_type: 'school' as const,
        groups: [{ key: 'g', label: 'G', entries: [
            { key: 'events', default_at_creation: true },
            { key: 'donations', default_at_creation: true },
        ] }],
    } as unknown as Parameters<typeof featureSummary>[1];

    assert.deepEqual(featureSummary({ events: true, donations: false }, catalogue), { on: 1, total: 2, departures: 1 });
    assert.deepEqual(featureSummary({ events: true }, null), { on: 1, total: 1, departures: null });
});
