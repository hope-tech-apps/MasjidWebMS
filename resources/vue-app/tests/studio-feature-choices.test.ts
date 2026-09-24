/**
 * Studio's feature step rules (core/studio/featureChoices.ts): the draft holds
 * the full map of served keys (R9), a stored choice is never overridden,
 * `preselect_with` ticks an unset switch only when its platform is chosen, a
 * switch nobody moved follows the organisation type and platforms when they
 * change, and the groups keep the served order with `not_offered` rows set apart.
 * The catalogue here is a made-up fixture; its keys mean nothing to the code.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { carryChoices, featureGroups, fullChoiceMap, preselectMatches, sameChoices, servedEntries } from '../core/studio/featureChoices.ts';

const entry = (key: string, extra: Record<string, unknown> = {}) => ({
    key,
    kind: 'module',
    label: `Label ${key}`,
    description: '',
    turns_on: `Turns on ${key}`,
    writer: 'capability',
    default_for_org_type: null,
    offered_by_default: true,
    default_at_creation: false,
    visibility: 'optional',
    preselect_with: [] as string[],
    where: null,
    surface: null,
    app: { items: [] as string[], tab: false },
    ...extra,
});

const catalogue = () => ({
    org_type: 'masjid' as const,
    groups: [
        { key: 'g2', label: 'Second in config, served first', entries: [entry('born_on', { default_at_creation: true, visibility: 'default' }), entry('rare', { visibility: 'not_offered' })] },
        { key: 'g1', label: 'Served second', entries: [entry('site_grant', { kind: 'grant', preselect_with: ['web'] }), entry('plain')] },
    ],
});

test('an empty draft gets every served key: its default at creation, and nothing else', () => {
    assert.deepEqual(fullChoiceMap(catalogue(), undefined, ['ios']), { born_on: true, rare: false, site_grant: false, plain: false });
});

test('preselect_with ticks an unset switch when its platform is chosen', () => {
    assert.equal(fullChoiceMap(catalogue(), {}, ['ios', 'web']).site_grant, true);
    assert.deepEqual(preselectMatches(servedEntries(catalogue())[2], ['ios', 'web']), ['web']);
    assert.deepEqual(preselectMatches(servedEntries(catalogue())[2], ['ios', 'android']), []);
});

test("a stored choice is the operator's and is kept, even against a default or a preselect", () => {
    const map = fullChoiceMap(catalogue(), { born_on: false, site_grant: false, rare: true }, ['web']);
    assert.deepEqual(map, { born_on: false, rare: true, site_grant: false, plain: false });
});

test('a stored key the catalogue no longer serves is dropped, and a non-boolean is not a choice', () => {
    const map = fullChoiceMap(catalogue(), { hidden_now: true, plain: 'yes' }, []);
    assert.equal('hidden_now' in map, false);
    assert.equal(map.plain, false);
});

test('sameChoices is true only for the same keys with the same values', () => {
    const full = fullChoiceMap(catalogue(), {}, []);
    assert.equal(sameChoices({ ...full }, full), true);
    assert.equal(sameChoices({}, full), false);
    assert.equal(sameChoices(undefined, full), false);
    assert.equal(sameChoices({ ...full, plain: true }, full), false);
    assert.equal(sameChoices({ ...full, extra: false }, full), false);
});

test('groups keep the order served and set not_offered rows apart, in order', () => {
    const groups = featureGroups(catalogue());
    assert.deepEqual(groups.map((group) => group.key), ['g2', 'g1']);
    assert.deepEqual(groups[0].offered.map((row) => row.key), ['born_on']);
    assert.deepEqual(groups[0].notOffered.map((row) => row.key), ['rare']);
    assert.deepEqual(groups[1].notOffered, []);
});

/** The same keys served for two organisation types, as CapabilityCatalogue serves them: one type's worship module is the other's not_offered. */
const typed = (orgType: 'masjid' | 'school') => ({
    org_type: orgType,
    groups: [
        {
            key: 'g', label: 'Group', entries: [
                entry('worship', orgType === 'masjid'
                    ? { default_at_creation: true, visibility: 'default' }
                    : { default_at_creation: false, visibility: 'not_offered' }),
                entry('shared', { default_at_creation: true, visibility: 'default' }),
                entry('site_grant', { kind: 'grant', preselect_with: ['web'] }),
            ],
        },
    ],
});

const at = (orgType: string | null, platforms: string[]) => ({ orgType, platforms });

test("a map filled for a masjid does not follow the draft to school: the worship switch comes back off", () => {
    const masjid = carryChoices(typed('masjid'), undefined, at('masjid', ['ios']), at('masjid', ['ios'])).choices;
    assert.deepEqual(masjid, { worship: true, shared: true, site_grant: false });

    // The type changes on Foundation while the masjid catalogue is still loaded: nothing carries over.
    const changed = carryChoices(typed('masjid'), masjid, at('masjid', ['ios']), at('school', ['ios']));
    assert.deepEqual(changed, { choices: null, settled: true });

    // Once the school's catalogue is here, the map starts from the school's defaults.
    const school = carryChoices(typed('school'), changed.choices, at('school', ['ios']), at('school', ['ios'])).choices;
    assert.deepEqual(school, { worship: false, shared: true, site_grant: false });

    // And with the school's catalogue already here, the same.
    assert.equal(carryChoices(typed('school'), masjid, at('masjid', ['ios']), at('school', ['ios'])).choices?.worship, false);
});

test('a switch nobody moved follows a platform added after the first visit, and back', () => {
    const iosOnly = carryChoices(catalogue(), undefined, at('masjid', ['ios']), at('masjid', ['ios'])).choices;
    assert.equal(iosOnly?.site_grant, false);

    const withWeb = carryChoices(catalogue(), iosOnly, at('masjid', ['ios']), at('masjid', ['ios', 'web'])).choices;
    assert.equal(withWeb?.site_grant, true, 'preselect_with applies when the selected platforms match');

    const webRemoved = carryChoices(catalogue(), withWeb, at('masjid', ['ios', 'web']), at('masjid', ['ios'])).choices;
    assert.equal(webRemoved?.site_grant, false);
});

test("a switch the operator moved keeps their choice when the platforms change", () => {
    const stored = { born_on: false, rare: false, site_grant: true, plain: true };
    const out = carryChoices(catalogue(), stored, at('masjid', ['ios', 'web']), at('masjid', ['ios'])).choices;

    // born_on was moved off its default and plain on: both stay. site_grant sat
    // where Web put it, so it follows Web out.
    assert.deepEqual(out, { born_on: false, rare: false, site_grant: false, plain: true });
});

test('without this type\'s catalogue a stored map is kept and waits, and an empty one needs nothing', () => {
    const stored = { born_on: true, rare: false, site_grant: false, plain: false };

    assert.deepEqual(carryChoices(null, stored, at('masjid', ['ios']), at('masjid', ['ios', 'web'])), { choices: stored, settled: false });
    assert.deepEqual(carryChoices(null, stored, at('masjid', ['ios']), at('masjid', ['ios'])), { choices: stored, settled: true });
    assert.deepEqual(carryChoices(null, {}, at('masjid', ['ios']), at('masjid', ['web'])), { choices: null, settled: true });
});
