/**
 * Studio's feature step rules (core/studio/featureChoices.ts): the draft holds
 * the full map of served keys (R9), a stored choice is never overridden,
 * `preselect_with` ticks an unset switch only when its platform is chosen, a
 * switch nobody moved follows the organisation type and platforms when they
 * change, and the groups keep the served order with `not_offered` rows set apart.
 * Step 3's review and results count a switch a platform preselected as
 * suggested, never as changed: only what the operator moved is "changed by you".
 * The catalogue here is a made-up fixture; its keys mean nothing to the code.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    appliedText,
    carryChoices,
    featureCounts,
    featureCountsText,
    featureGroups,
    fullChoiceMap,
    preselectMatches,
    sameChoices,
    servedEntries,
    splitApplied,
} from '../core/studio/featureChoices.ts';

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

// ---- Step 3's counts: a platform's preselection is a suggestion, not a change (NAFIS walkthrough) ----

const label = (platform: string) => ({ web: 'Web', ios: 'iOS' }[platform] ?? platform);

test('the review counts a preselected switch nobody moved as suggested, not changed', () => {
    // What Studio itself starts a masjid with on iOS + Web: born_on by default, site_grant because of Web.
    const untouched = fullChoiceMap(catalogue(), {}, ['ios', 'web']);
    const counts = featureCounts(untouched, catalogue(), ['ios', 'web']);

    assert.deepEqual(counts, { on: 2, total: 4, suggested: 1, suggestedWith: ['web'], changed: 0 });
    assert.equal(featureCountsText(counts, label), '2 of 4 on, 1 suggested with Web, 0 changed by you');
});

test('only a switch moved away from where Studio put it is changed by you, including a suggestion turned off', () => {
    const map = { born_on: false, rare: true, site_grant: false, plain: false };
    const counts = featureCounts(map, catalogue(), ['ios', 'web']);

    // born_on off its default, rare on, site_grant off though Web suggests it: three edits, no suggestion left.
    assert.deepEqual(counts, { on: 1, total: 4, suggested: 0, suggestedWith: [], changed: 3 });
    assert.equal(featureCountsText(counts, label), '1 of 4 on, 3 changed by you');
});

test('without Web the same map has nothing suggested, and without a catalogue only the on count is said', () => {
    const noWeb = fullChoiceMap(catalogue(), {}, ['ios']);
    assert.equal(featureCountsText(featureCounts(noWeb, catalogue(), ['ios']), label), '1 of 4 on, 0 changed by you');

    const counts = featureCounts(noWeb, null, ['ios']);
    assert.deepEqual(counts, { on: 1, total: 4, suggested: null, suggestedWith: [], changed: null });
    assert.equal(featureCountsText(counts, label), '1 of 4 on');
    assert.equal(featureCountsText(featureCounts({}, catalogue(), []), label), '');
});

test("the results split the server's departures with the platforms it created the organisation with", () => {
    const applied = {
        changed: [{ key: 'site_grant', enabled: true }, { key: 'plain', enabled: true }],
        unchanged: ['born_on', 'rare'],
    };

    const split = splitApplied(applied, catalogue(), ['ios', 'web']);
    assert.deepEqual(split, {
        suggested: [{ key: 'site_grant', enabled: true, with: ['web'] }],
        suggestedWith: ['web'],
        byYou: [{ key: 'plain', enabled: true }],
    });
    assert.equal(appliedText(applied, split, label), "Features: 1 suggested with Web, 1 changed by you, 2 at a new organisation's defaults");

    // The walkthrough's case: nothing moved, Web chosen. Nothing is called changed.
    const onlyWeb = { changed: [{ key: 'site_grant', enabled: true }], unchanged: ['born_on', 'rare', 'plain'] };
    assert.equal(
        appliedText(onlyWeb, splitApplied(onlyWeb, catalogue(), ['web']), label),
        "Features: 1 suggested with Web, 0 changed by you, 3 at a new organisation's defaults",
    );

    // The same switch on without Web is the operator's own.
    assert.deepEqual(splitApplied(onlyWeb, catalogue(), ['ios'])?.byYou, [{ key: 'site_grant', enabled: true }]);
});

test('a suggestion the operator turned off is counted as their change, agreeing with the review', () => {
    // Web chosen, site_grant turned off: at its default, so the server lists it as unchanged.
    const applied = { changed: [], unchanged: ['born_on', 'rare', 'site_grant', 'plain'] };
    const split = splitApplied(applied, catalogue(), ['web']);
    assert.deepEqual(split?.byYou, [{ key: 'site_grant', enabled: false }]);
    assert.deepEqual(split?.suggested, []);
    assert.equal(appliedText(applied, split, label), "Features: 1 changed by you, 3 at a new organisation's defaults");

    const map = { ...fullChoiceMap(catalogue(), {}, ['web']), site_grant: false };
    assert.equal(featureCounts(map, catalogue(), ['web']).changed, 1);

    // Without Web there was no suggestion to turn off.
    assert.deepEqual(splitApplied(applied, catalogue(), ['ios'])?.byYou, []);
});

test("when the split cannot be told, the results say only what the server's list means", () => {
    const applied = { changed: [{ key: 'site_grant', enabled: true }], unchanged: ['born_on', 'rare', 'plain'] };

    assert.equal(splitApplied(applied, null, ['web']), null, 'no catalogue');
    assert.equal(splitApplied(applied, catalogue(), null), null, 'no platforms reported');
    assert.equal(splitApplied({ changed: [{ key: 'unknown_key', enabled: true }], unchanged: [] }, catalogue(), ['web']), null, 'a key the catalogue does not describe');

    assert.equal(appliedText(applied, null), "Features: 1 differs from a new organisation's defaults, 3 match them");
    assert.equal(appliedText({ changed: [], unchanged: ['a'] }, null), "Features: 0 differ from a new organisation's defaults, 1 matches them");
});

const review = readFileSync(new URL('../components/super/studio/generate/ReviewGrid.vue', import.meta.url), 'utf8');
const results = readFileSync(new URL('../components/super/studio/generate/ProvisionResults.vue', import.meta.url), 'utf8');

test('neither Step 3 screen calls a preselection a change any more', () => {
    for (const [name, code] of [['ReviewGrid', review], ['ProvisionResults', results]] as const) {
        assert.doesNotMatch(code, /changed from the defaults/, `${name} still says "changed from the defaults"`);
    }

    assert.match(review, /featureCounts\(answers\.features\.capabilities, catalogue, answers\.platforms\.platforms \?\? \[\]\)/);
    assert.match(review, /featureCountsText\(features, platformLabel\)/);

    // The platforms the SERVER created the organisation with, never the draft's answers (StudioGenerateStepSourceTest).
    assert.match(results, /splitApplied\(applied\.value, catalogueFor\(props\.result\.masjid\.org_type\), props\.result\.app_publishing\?\.enabled_platforms\)/);
    assert.match(results, /\{\{ appliedText\(applied, split, platformLabel\) \}\}/);
    assert.doesNotMatch(results, /store\.answers/);
});
