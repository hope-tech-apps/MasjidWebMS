#!/usr/bin/env node
//
// Regenerates tests/fixtures/adhan-prayer-times.json — the golden vectors that
// tests/Unit/AdhanPrayerTimesTest.php compares App\Services\PrayerTimes against.
//
// The whole point of that fixture is that it is evidence produced by a DIFFERENT
// implementation. It must therefore only ever be written by this script, which
// computes every payload with adhan-js itself. Never regenerate it with the PHP
// port: a fixture produced by the code under test proves nothing.
//
//   Run:      TZ=UTC node tests/fixtures/generate-adhan-vectors.mjs
//   Verify:   TZ=UTC node tests/fixtures/generate-adhan-vectors.mjs --check
//
// On this machine node is not on PATH; use the absolute interpreter:
//   /Users/moneebsayed/.nvm/versions/node/v22.11.0/bin/node
//
// --------------------------------------------------------------------------
// adhan-js 4.4.4 is REQUIRED. 4.4.3 produces a different fixture.
// --------------------------------------------------------------------------
// 4.4.4 carries exactly one behavioural change over 4.4.3 —
// "fix(prayertimes): fix idl edge case" — in Astronomical.approximateTransit:
//
//     const m0 = normalizeToScale((a2 + Lw - Theta0) / 360, 1);
//     const expectedTransit = normalizeToScale((12.0 - L / 15.0) / 24.0, 1);
//     if (m0 - expectedTransit > 0.5) return m0 - 1.0;
//     else if (expectedTransit - m0 > 0.5) return m0 + 1.0;
//     else return m0;
//
// Near the International Date Line the un-corrected m0 lands on the wrong side
// of the 0h/24h wrap, so every prayer for that day is emitted a full day off.
// App\Services\PrayerTimes\Astronomical::approximateTransit() already implements
// this correction verbatim — the PHP port is a port of 4.4.4, not of 4.4.3.
// Generating this fixture with 4.4.3 would therefore write three suva_fiji
// vectors that contradict the shipped port and turn the golden-vector test red
// against correct code. Hence the hard version floor below.
//
// NOTE: package.json still declares "adhan": "^4.4.3" and the lockfile pins
// 4.4.3. Bump it to ^4.4.4 so this script is reproducible from a clean install.
// Until then, point it at an install of your own:
//   ADHAN_PATH=/path/to/node_modules/adhan TZ=UTC node …/generate-adhan-vectors.mjs
//
// --------------------------------------------------------------------------
// Why the process timezone matters
// --------------------------------------------------------------------------
// adhan-js reads the civil day off the Date you hand it with the LOCAL accessors
// date.getFullYear()/getMonth()/getDate() (PrayerTimes.js). We hand it
// Date.UTC(y, m-1, d), so anywhere west of Greenwich those accessors would
// report the previous day and every vector would silently shift by 24h. The
// guard below makes that impossible to get wrong.
//
// --------------------------------------------------------------------------
// The payload shape
// --------------------------------------------------------------------------
// `expected` is literally JSON.stringify(new adhan.PrayerTimes(...)) — nothing
// is reshaped here. That is deliberate: the stored prayers_data column IS this
// JSON text, so key order, number formatting and nulls are all part of the
// contract, and the PHP port is compared against it as encoded JSON.
//
// Coordinates are passed as the DB's decimal STRINGS ("35.78056000"), exactly as
// App\Models\Masjid hands them over. adhan never adds a coordinate to anything —
// it only feeds them through degreesToRadians() and numeric comparisons — so the
// strings coerce cleanly and survive into the payload verbatim.

import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join, resolve } from 'node:path';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const HERE = dirname(fileURLToPath(import.meta.url));
const FIXTURE = join(HERE, 'adhan-prayer-times.json');

// --------------------------------------------------------------------------
// Guards
// --------------------------------------------------------------------------

if (new Date().getTimezoneOffset() !== 0) {
  console.error(
    'Refusing to run outside UTC: adhan-js reads the civil day with local Date ' +
      'accessors, so a non-UTC process would shift every vector by a day.\n' +
      'Re-run as: TZ=UTC node tests/fixtures/generate-adhan-vectors.mjs',
  );
  process.exit(1);
}

const ADHAN_DIR = process.env.ADHAN_PATH
  ? resolve(process.env.ADHAN_PATH)
  : join(HERE, '..', '..', 'node_modules', 'adhan');

const adhanPkg = JSON.parse(readFileSync(join(ADHAN_DIR, 'package.json'), 'utf8'));
const ADHAN_VERSION = adhanPkg.version;

const MINIMUM_ADHAN = [4, 4, 4];
const installed = ADHAN_VERSION.split('.').map(Number);
const compareVersions = (a, b) => {
  for (let i = 0; i < 3; i += 1) {
    if ((a[i] || 0) !== (b[i] || 0)) return (a[i] || 0) - (b[i] || 0);
  }
  return 0;
};

if (compareVersions(installed, MINIMUM_ADHAN) < 0) {
  console.error(
    `Refusing to run: adhan-js ${ADHAN_VERSION} is installed at ${ADHAN_DIR}, but this\n` +
      'fixture requires >= 4.4.4. 4.4.4 fixes an International Date Line bug in\n' +
      'Astronomical.approximateTransit that shifts every prayer of the day by 24h at\n' +
      'longitudes near +/-180 (suva_fiji, 178.4419). App\\Services\\PrayerTimes already\n' +
      'implements the fixed algorithm, so regenerating with an older adhan would write\n' +
      'vectors that contradict the port and fail the suite against correct code.\n\n' +
      '  npm install adhan@^4.4.4\n' +
      '  # or, without touching the project: ADHAN_PATH=/elsewhere/node_modules/adhan',
  );
  process.exit(1);
}

// 4.4.3 is CommonJS; 4.4.4 sets "type": "module", which makes its own lib/cjs
// build unloadable by require(). Pick the entry point that actually works.
const adhan =
  adhanPkg.type === 'module'
    ? await import(pathToFileURL(join(ADHAN_DIR, adhanPkg.module)).href)
    : createRequire(import.meta.url)(ADHAN_DIR);

// --------------------------------------------------------------------------
// Locations. Keyed by the fixture's `location` label; the coordinate strings are
// byte-identical to the ones already in the fixture so existing vectors keep
// reproducing.
// --------------------------------------------------------------------------

const LOCATIONS = {
  // Mid-latitude northern. The masjid the production rows actually belong to.
  raleigh_nc: ['35.78056000', '-78.63890000'],
  greensboro_nc: ['36.07330000', '-79.45650000'],
  makkah: ['21.42250000', '39.82620000'],
  // Southern tropics, UTC+7: sunrise lands on the PREVIOUS UTC day.
  jakarta: ['-6.20880000', '106.84560000'],
  // 51.5N — past HighLatitudeRule.recommended()'s latitude > 48 threshold.
  london: ['51.50740000', '-0.12780000'],
  // 64.1N — below the polar circle but past MoonsightingCommittee's >= 55
  // branch, and far enough north that the night-portion rule binds fajr/isha.
  reykjavik: ['64.14660000', '-21.94260000'],
  anchorage: ['61.21810000', '-149.90030000'],
  // Southern hemisphere, UTC+12, other side of the date line.
  auckland: ['-36.84850000', '174.76330000'],
  suva_fiji: ['-18.14160000', '178.44190000'],
  // Equatorial.
  quito: ['-0.18070000', '-78.46780000'],
  // 69.6N — INSIDE the polar circle. With polarCircleResolution 'Unresolved'
  // the midnight-sun / polar-night days come back null.
  tromso: ['69.64920000', '18.95530000'],
  // 54.8S — the southern-hemisphere counterpart of the high-latitude cases.
  ushuaia: ['-54.80190000', '-68.30300000'],
  apia_samoa: ['-13.75900000', '-172.10460000'],
  null_island: ['0.00000000', '0.00000000'],
};

// --------------------------------------------------------------------------
// Dates
// --------------------------------------------------------------------------

// The original 18: both solstices, an equinox, a leap day, month/year edges.
const LEGACY_DATES = [
  '2025-01-01',
  '2025-02-14',
  '2025-03-20',
  '2025-04-05',
  '2025-05-31',
  '2025-06-21',
  '2025-07-04',
  '2025-08-11',
  '2025-09-22',
  '2025-10-31',
  '2025-11-30',
  '2025-12-17',
  '2025-12-31',
  '2024-02-29',
  '2024-12-21',
  '2026-01-01',
  '2026-03-01',
  '2026-06-21',
];

const LEGACY_VARIANT_DATES = [
  '2025-01-01',
  '2025-03-20',
  '2025-06-21',
  '2025-09-22',
  '2025-12-17',
  '2024-02-29',
];

// The four turning points of the solar year. Between them they put every
// location through its longest day, its shortest day, and the two days the
// declination crosses zero — which is where the high-latitude and polar
// branches switch on and off.
const SWEEP_DATES = [
  '2025-03-20', // March equinox
  '2025-06-21', // June solstice
  '2025-09-22', // September equinox
  '2025-12-21', // December solstice
];

// --------------------------------------------------------------------------
// Parameters
// --------------------------------------------------------------------------

// Every case of App\Enums\PrayerCalculationMethod, in enum declaration order.
// All twelve exist in adhan-js under the same name. adhan's thirteenth method,
// 'Other', is deliberately absent: the app does not offer it.
const METHODS = [
  'MuslimWorldLeague',
  'Egyptian',
  'Karachi',
  'UmmAlQura',
  'Dubai',
  'MoonsightingCommittee',
  'NorthAmerica',
  'Kuwait',
  'Qatar',
  'Singapore',
  'Tehran',
  'Turkey',
];

// `overrides` is expressed in the port's own serialized constants — the
// LOWERCASE forms, which is what ends up in prayers_data.calculationParameters
// and what the PHP test assigns straight onto the params object. The enum's
// StudlyCase spellings ('Shafi', 'MiddleOfTheNight') never appear here.
const MADHABS = [adhan.Madhab.Shafi, adhan.Madhab.Hanafi]; // 'shafi', 'hanafi'
const HIGH_LATITUDE_RULES = [
  adhan.HighLatitudeRule.MiddleOfTheNight, // 'middleofthenight'
  adhan.HighLatitudeRule.SeventhOfTheNight, // 'seventhofthenight'
  adhan.HighLatitudeRule.TwilightAngle, // 'twilightangle'
];

// The twelve variant combinations the fixture already carried, in order. These
// reach the knobs the 72-combo sweep below does not vary: shafaq, rounding and
// manual adjustments.
const LEGACY_VARIANTS = [
  { method: 'MuslimWorldLeague', overrides: {} },
  { method: 'UmmAlQura', overrides: {} },
  { method: 'Tehran', overrides: {} },
  { method: 'MoonsightingCommittee', overrides: { madhab: 'hanafi' } },
  { method: 'MoonsightingCommittee', overrides: { shafaq: 'ahmer' } },
  { method: 'MoonsightingCommittee', overrides: { shafaq: 'abyad' } },
  { method: 'MoonsightingCommittee', overrides: { rounding: 'up' } },
  { method: 'MoonsightingCommittee', overrides: { rounding: 'none' } },
  {
    method: 'MoonsightingCommittee',
    overrides: {
      adjustments: { fajr: -7, sunrise: 2, dhuhr: 4, asr: -3, maghrib: 6, isha: 11 },
    },
  },
  { method: 'MuslimWorldLeague', overrides: { highLatitudeRule: 'seventhofthenight' } },
  { method: 'MuslimWorldLeague', overrides: { highLatitudeRule: 'twilightangle' } },
  {
    method: 'MuslimWorldLeague',
    overrides: { madhab: 'hanafi', highLatitudeRule: 'seventhofthenight' },
  },
];

const LEGACY_VARIANT_LOCATIONS = ['raleigh_nc', 'makkah', 'jakarta', 'tromso', 'ushuaia'];

// Six locations chosen because each one takes a different BRANCH, not because
// it produces different numbers.
const SWEEP_LOCATIONS = [
  'raleigh_nc', // mid-latitude northern, the ordinary path
  'quito', // equatorial: night portions are never reached
  'auckland', // southern hemisphere, and east of the date line
  'reykjavik', // 64N: night-portion rule binds; MoonsightingCommittee >= 55
  'tromso', // inside the polar circle: nulls at the solstices
  'jakarta', // sunrise falls on a different UTC day than the civil date
];

// --------------------------------------------------------------------------
// Computation
// --------------------------------------------------------------------------

function buildParams(method, overrides) {
  const factory = adhan.CalculationMethod[method];
  if (typeof factory !== 'function') {
    throw new Error(`adhan-js has no calculation method named '${method}'.`);
  }

  const params = factory();

  // Mirrors AdhanPrayerTimesTest::params() exactly: 'adjustments' merges into
  // the existing map (keeping its fajr..isha key order), everything else is a
  // straight property assignment.
  for (const [key, value] of Object.entries(overrides)) {
    if (key === 'adjustments') {
      Object.assign(params.adjustments, value);
    } else {
      params[key] = value;
    }
  }

  return params;
}

function compute(location, method, overrides, date) {
  const [latitude, longitude] = LOCATIONS[location];
  const [year, month, day] = date.split('-').map(Number);

  const times = new adhan.PrayerTimes(
    new adhan.Coordinates(latitude, longitude),
    new Date(Date.UTC(year, month - 1, day)),
    buildParams(method, overrides),
  );

  return {
    location,
    method,
    overrides,
    latitude,
    longitude,
    date,
    // Round-tripped so `expected` holds precisely what JSON.stringify of adhan's
    // own object produces — Invalid Date collapses to null here, not later.
    expected: JSON.parse(JSON.stringify(times)),
  };
}

// --------------------------------------------------------------------------
// The matrix
// --------------------------------------------------------------------------

const vectors = [];

// Block 1 — baseline. Every location on every date under the shipping default
// (MoonsightingCommittee / shafi / middleofthenight), which is the combination
// every production row was written with.
for (const location of Object.keys(LOCATIONS)) {
  for (const date of LEGACY_DATES) {
    vectors.push(compute(location, 'MoonsightingCommittee', {}, date));
  }
}

// Block 2 — the original variant sweep: shafaq, rounding, manual adjustments and
// the first high-latitude rules, at the five most interesting locations.
for (const variant of LEGACY_VARIANTS) {
  for (const location of LEGACY_VARIANT_LOCATIONS) {
    for (const date of LEGACY_VARIANT_DATES) {
      vectors.push(compute(location, variant.method, variant.overrides, date));
    }
  }
}

// Block 3 — the full settings matrix. Everything a masjid can actually select in
// prayer_calculation_settings: 12 methods x 2 madhabs x 3 high-latitude rules =
// 72 combinations, each run at all six branch locations on all four solar
// turning points. madhab and highLatitudeRule are written out even when they
// equal the default, so a vector states the whole combination it stands for.
for (const method of METHODS) {
  for (const madhab of MADHABS) {
    for (const highLatitudeRule of HIGH_LATITUDE_RULES) {
      const overrides = { madhab, highLatitudeRule };

      for (const location of SWEEP_LOCATIONS) {
        for (const date of SWEEP_DATES) {
          vectors.push(compute(location, method, overrides, date));
        }
      }
    }
  }
}

// --------------------------------------------------------------------------
// Reproduction check against whatever is already on disk
// --------------------------------------------------------------------------
//
// Any vector the fixture already contains must come back byte-identical. If it
// does not, the fixture and the library disagree and that is a finding in its
// own right — stop rather than quietly overwrite the evidence.

const identity = (v) => `${v.location}|${v.method}|${JSON.stringify(v.overrides)}|${v.date}`;

let reproduced = 0;
const regressions = [];

if (existsSync(FIXTURE)) {
  const stored = JSON.parse(readFileSync(FIXTURE, 'utf8'));
  const fresh = new Map(vectors.map((v) => [identity(v), v]));

  for (const old of stored) {
    const now = fresh.get(identity(old));

    if (!now) {
      regressions.push({ id: identity(old), reason: 'dropped from the matrix' });
      continue;
    }

    if (JSON.stringify(now.expected) !== JSON.stringify(old.expected)) {
      regressions.push({
        id: identity(old),
        reason: 'payload changed',
        was: JSON.stringify(old.expected),
        now: JSON.stringify(now.expected),
      });
      continue;
    }

    reproduced += 1;
  }

  console.log(`reproduced ${reproduced}/${stored.length} pre-existing vectors`);

  if (regressions.length > 0) {
    console.error(`\n${regressions.length} vector(s) did NOT reproduce:`);
    for (const r of regressions.slice(0, 10)) {
      console.error(`  ${r.id}: ${r.reason}`);
      if (r.was) {
        console.error(`    was: ${r.was}`);
        console.error(`    now: ${r.now}`);
      }
    }
    process.exit(1);
  }
}

// --------------------------------------------------------------------------
// Emit
// --------------------------------------------------------------------------
//
// One vector per line. The array is valid JSON either way, but a line per vector
// is what makes the file reviewable: adding a location or a date shows up as
// added lines instead of rewriting one 2 MB line.

const json = `[\n${vectors.map((v) => JSON.stringify(v)).join(',\n')}\n]\n`;

if (process.argv.includes('--check')) {
  const onDisk = existsSync(FIXTURE) ? readFileSync(FIXTURE, 'utf8') : '';
  if (onDisk !== json) {
    console.error('Fixture is stale: regenerate it with this script.');
    process.exit(1);
  }
  console.log(`fixture is current (${vectors.length} vectors)`);
} else {
  writeFileSync(FIXTURE, json);
  console.log(
    `wrote ${vectors.length} vectors to ${FIXTURE} ` +
      `(${(Buffer.byteLength(json) / 1024 / 1024).toFixed(2)} MB, adhan-js ${ADHAN_VERSION})`,
  );
}
