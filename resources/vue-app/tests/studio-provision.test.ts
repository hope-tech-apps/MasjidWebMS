/**
 * Step 3's pure rules (core/studio/provision.ts): the gate that keeps Provision
 * disabled (R27 and the BYO credentials), the body that carries the credentials
 * and nothing else (R7), how the answer is read (a 201 without
 * `capabilities_applied` is not success; a 409 is the organisation that
 * exists), and when an invitation may be called sent.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    byoPlatforms,
    clearSecrets,
    emptySecrets,
    featureSummary,
    generateBlockers,
    inviteOutcome,
    provisionBody,
    readProvisionOutcome,
    webGateBlockers,
} from '../core/studio/provision.ts';
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

test('a 201 with capabilities_applied is created', () => {
    const outcome = readProvisionOutcome(201, created());
    assert.equal(outcome.kind, 'created');
    assert.equal(outcome.kind === 'created' && outcome.result.masjid_id, 42);
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

test('a 422 lists every message of the legacy envelope once', () => {
    const outcome = readProvisionOutcome(422, {
        status: 'failed',
        data: { logo: [LOGO], 'brand.primary_color': ['Choose this colour as #RRGGBB.'], slug: [LOGO] },
    });
    assert.deepEqual(outcome, { kind: 'invalid', messages: [LOGO, 'Choose this colour as #RRGGBB.'] });
});

test('a 500 and a lost answer are failures that say nothing was, or may have been, created', () => {
    const failed = readProvisionOutcome(500, { status: 'error', data: 'Something went wrong.' });
    assert.deepEqual(failed, { kind: 'failed', message: 'The organisation was not created. Something went wrong.' });

    const lost = readProvisionOutcome(undefined, undefined);
    assert.equal(lost.kind, 'failed');
    assert.match(lost.kind === 'failed' ? lost.message : '', /Trying again is safe/);
});

test('an invitation is called sent only when one went and none failed', () => {
    const answers = ready();

    assert.deepEqual(inviteOutcome({ invites_sent: 1, invites_failed: 0, warnings: [] }, answers),
        { sent: true, text: 'Invitation sent to office@alnoor.example.' });

    assert.deepEqual(inviteOutcome({ invites_sent: 0, invites_failed: 1, warnings: ['x'] }, answers),
        { sent: false, text: 'The invitation was not sent.' });

    assert.equal(inviteOutcome({ invites_sent: 1, invites_failed: 1, warnings: [] }, answers).sent, false);
    assert.equal(inviteOutcome(undefined, answers).sent, false);

    const existing = ready();
    existing.identity.user_id = 9;
    assert.deepEqual(inviteOutcome({ invites_sent: 0, invites_failed: 0, warnings: [] }, existing),
        { sent: false, text: 'No invitation was sent: an existing account was made the administrator.' });
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
