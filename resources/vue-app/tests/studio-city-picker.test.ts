/**
 * Studio's city picker (core/studio/citySearch.ts, IdentityPanel.vue): a
 * country's cities are searched as the operator types, never loaded whole into
 * a select (the US has 21,008, several names more than once with no state).
 * The pure rules first: when a search is sent, what an empty answer says, the
 * arrow keys, and what leaving the box does. Then IdentityPanel and the store
 * read as text, as form-response-status.test.ts does, to pin the wiring: the
 * SPA has no DOM test harness. The legacy wizard and the super masjid form are
 * pinned to the full list they still read.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    CITY_QUERY_MAX,
    CITY_SEARCH_DEBOUNCE_MS,
    CITY_SEARCH_LIMIT,
    CITY_SEARCH_MIN_CHARS,
    cityOnLeave,
    citySearchText,
    MORE_CITIES_TEXT,
    moveActive,
    noCityText,
} from '../core/studio/citySearch.ts';

const read = (path: string) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('a search is sent for two or more letters, trimmed, and never for fewer', () => {
    assert.equal(CITY_SEARCH_MIN_CHARS, 2);
    assert.equal(citySearchText(''), null);
    assert.equal(citySearchText('  R '), null);
    assert.equal(citySearchText(' Ra '), 'Ra');
    assert.equal(citySearchText('North Raleigh'), 'North Raleigh');
    // Letters, not UTF-16 units: one astral character is one.
    assert.equal(citySearchText('𝒜'), null);
});

test('the timings and limits are the brief\'s and the server\'s', () => {
    assert.equal(CITY_SEARCH_DEBOUNCE_MS, 250);
    // CountriesCitiesController::SEARCH_LIMIT and CountryCitiesRequest::QUERY_MAX.
    assert.equal(CITY_SEARCH_LIMIT, 50);
    assert.equal(CITY_QUERY_MAX, 100);
    assert.match(read('../../app/Http/Controllers/AdminDashboard/CountriesCitiesController.php'), /public const SEARCH_LIMIT = 50;/);
    assert.match(read('../../app/Http/Requests/Admin/Countries/CountryCitiesRequest.php'), /public const QUERY_MAX = 100;/);
});

test('an empty answer says what was searched, and a full page says there may be more', () => {
    assert.equal(noCityText('Zzq'), 'No city starts with or contains “Zzq”.');
    assert.equal(MORE_CITIES_TEXT, 'Showing the first 50. Type more of the name to narrow them.');
});

test('the arrow keys move through the suggestions and wrap at both ends', () => {
    assert.equal(moveActive(-1, 3, 'ArrowDown'), 0);
    assert.equal(moveActive(2, 3, 'ArrowDown'), 0);
    assert.equal(moveActive(-1, 3, 'ArrowUp'), 2);
    assert.equal(moveActive(0, 3, 'ArrowUp'), 2);
    assert.equal(moveActive(1, 3, 'ArrowUp'), 0);
    assert.equal(moveActive(0, 0, 'ArrowDown'), -1);
});

test('only a pick sets the city: text left behind goes back to the chosen name, an emptied box clears it', () => {
    assert.deepEqual(cityOnLeave('Ral', 'Raleigh', true), { clear: false, text: 'Raleigh' });
    assert.deepEqual(cityOnLeave('   ', 'Raleigh', true), { clear: true, text: '' });
    // A focus and a blur with no typing never clears, even when the stored name could not be loaded.
    assert.deepEqual(cityOnLeave('', '', false), { clear: false, text: '' });
    assert.deepEqual(cityOnLeave('', 'Raleigh', false), { clear: false, text: 'Raleigh' });
});

const panel = read('components/super/studio/foundation/IdentityPanel.vue');
const store = read('stores/super/studioDraftStore.ts');

test('IdentityPanel no longer renders one <option> per city: the city is an ARIA combobox', () => {
    assert.doesNotMatch(panel, /<select id="studio-city"/);
    assert.doesNotMatch(panel, /<option v-for="city in/);
    assert.doesNotMatch(panel, /fetchCities/);

    const input = panel.match(/<input id="studio-city"[\s\S]*?\/>/)?.[0] ?? '';
    assert.notEqual(input, '', 'the city box is where the test expects it');
    for (const attr of [
        'role="combobox"',
        'aria-autocomplete="list"',
        'aria-controls="studio-city-list"',
        ':aria-expanded="cityListOpen"',
        ':aria-activedescendant=',
        '@input="onCityInput"',
        '@keydown="onCityKey"',
        '@blur="onCityBlur"',
    ]) {
        assert.ok(input.includes(attr), `the city box lacks ${attr}`);
    }

    // The listbox is always in the DOM so aria-controls names something, and its options are the ids the box points at.
    assert.match(panel, /<ul v-show="cityListOpen" id="studio-city-list" role="listbox"/);
    assert.match(panel, /:id="`studio-city-option-\$\{i\}`"[^>]*role="option"/);
    assert.match(panel, /@mousedown\.prevent="pickCity\(city\)"/);
});

test('a pick stores the id, a stale answer is dropped, and a stored id is named once through ?id=', () => {
    assert.match(panel, /identity\.value\.city_id = city\.id;/);
    assert.match(panel, /if \(seq !== citySeq \|\| identity\.value\.country_id !== countryId\) return;/);
    assert.match(panel, /setTimeout\(\(\) => \{ void searchCities\(countryId, query\); \}, CITY_SEARCH_DEBOUNCE_MS\)/);
    assert.match(panel, /await store\.fetchCity\(countryId, cityId\)/);
    assert.match(panel, /if \(key === cityShownFor\) return;/);
    assert.match(panel, /noCityText\(query\)/);
    // Changing the country clears the city, as before.
    assert.match(panel, /function onCountryChange\(\) \{\s*identity\.value\.city_id = null;\s*\}/);
});

test('the store searches with ?q= and names with ?id=, and reports a failure instead of an empty list', () => {
    assert.match(store, /ApiService\.get\(`\/api\/admin\/countries\/\$\{countryId\}\/cities\?q=\$\{encodeURIComponent\(query\)\}`\)/);
    assert.match(store, /ApiService\.get\(`\/api\/admin\/countries\/\$\{countryId\}\/cities\?id=\$\{cityId\}`\)/);
    assert.match(store, /async function searchCities\(countryId: number, query: string\): Promise<Outcome<City\[\]>>/);
    assert.match(store, /async function fetchCity\(countryId: number, cityId: number\): Promise<Outcome<City \| null>>/);
    assert.doesNotMatch(store, /cities`\)/, 'Studio never fetches a country\'s whole list');

    const routes = read('core/types/config/BackendApiRoutes.ts');
    assert.ok(routes.includes('| `/api/admin/countries/${number}/cities?q=${string}`'));
    assert.ok(routes.includes('| `/api/admin/countries/${number}/cities?id=${number}`'));
});

test('the review names the city by id too, and the legacy screens keep the full list', () => {
    assert.match(read('components/super/studio/generate/ReviewGrid.vue'), /await store\.fetchCity\(countryId, cityId\)/);

    for (const legacy of ['views/dashboard/super/OnboardingWizardView.vue', 'views/dashboard/super/masjid/MasjidFormView.vue']) {
        const code = read(legacy);
        assert.match(code, /ApiService\.get\(`\/api\/admin\/countries\/\$\{[a-z_.]+\}\/cities`\)/, `${legacy} no longer reads the full list`);
        assert.match(code, /<option v-for="c(ty)? in cities"/, `${legacy} no longer renders its city select`);
    }
});
