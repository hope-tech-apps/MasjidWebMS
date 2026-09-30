#!/usr/bin/env node
// Builds database/curriculum/al-razi-qai-split-2026-27-q1.json from the school's
// own document, committed beside it as
// database/curriculum/sources/al-razi-detailed-pacing-plan-2026-09-07.txt.
//
//   node database/curriculum/tools/build-al-razi-qai-split.mjs
//
// The output is mechanical: every string is one whole line of the source text,
// copied by line number, never trimmed, joined, translated or completed. Islamic
// content is the school's and is imported verbatim. Any surprise (a moved
// heading, a header line that is not what it should be, a week cell that does
// not equal its row number, a hash that changed) is a non-zero exit and no
// file, because a guessed cell is worse than a missing one.
//
// Run it twice: the output must be byte-identical (it is a pure function of the
// two input files).

import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const curriculumDir = join(here, '..');
const SOURCE = join(curriculumDir, 'sources', 'al-razi-detailed-pacing-plan-2026-09-07.txt');
const BASE = join(curriculumDir, 'al-razi-pacing-2026-27.json');
const OUT = join(curriculumDir, 'al-razi-qai-split-2026-27-q1.json');

const TXT_SHA256 = '40cc44ac38a4b8b2be01fd07ef7fdf081682f0ff17ca78968992024a82ea8a0f';
const DOCX_SHA256 = '98332b22dd36298ed5d575f22e44745069fd94ddada25929fcfa1f6fe34119e1';

// Manara's labels, not content: the live guide's own spellings, because the
// lookups compare them exactly. Subject names are the catalogue's exact bytes
// (U+0027 in Qur'an), so the guide and the school's subject list are one entry.
const QURAN = "Qur'an";
const ARABIC = 'Arabic Language';
const ISLAMIC = 'Islamic Studies';
const COMBINED = 'Qur’an & Islamic Studies'; // U+2019, as in the base file

// The 12 quarter tables, in output order (grade, then subject). `heading` is the
// exact line at `heading` line number (trailing spaces kept); the table proper
// starts at `first` (the "Week" header cell) and ends at `last`.
const TABLES = [
  ['Pre-Kindergarten', QURAN, 1752, 'QUARTER 1 — PRE-K QUR’AN ', 1754, 1798],
  ['Pre-Kindergarten', ARABIC, 1256, 'QUARTER 1 — PRE-K ARABIC (AAL) (TABLE FORMAT)', 1258, 1302],
  ['Pre-Kindergarten', ISLAMIC, 1501, 'QUARTER 1 — PRE-K ISLAMIC STUDIES', 1503, 1547],
  ['Kindergarten', QURAN, 3923, 'QUARTER 1 — KINDERGARTEN QUR’AN & TAJWĪD ', 3925, 3969],
  ['Kindergarten', ARABIC, 3437, 'QUARTER 1 — KINDERGARTEN ARABIC (AAL)', 3439, 3483],
  ['Kindergarten', ISLAMIC, 3680, 'QUARTER 1 — KINDERGARTEN ISLAMIC STUDIES ', 3682, 3726],
  ['Grade 1', QURAN, 6354, 'QUARTER 1 — GRADE 1 QUR’AN & TAJWWĪD ', 6356, 6400],
  ['Grade 1', ARABIC, 5638, 'QUARTER 1 — GRADE 1 ARABIC (AAL)', 5640, 5684],
  ['Grade 1', ISLAMIC, 5877, 'QUARTER 1 — GRADE 1 ISLAMIC STUDIES ', 5879, 5923],
  ['Grade 2', QURAN, 8913, 'QUARTER 1 — GRADE 2 QUR’AN & TAJWĪD', 8915, 8959],
  ['Grade 2', ARABIC, 8040, 'QUARTER 1 — GRADE 2 ARABIC AAL', 8042, 8086],
  ['Grade 2', ISLAMIC, 8476, 'QUARTER 1 — GRADE 2 ISLAMIC STUDIES', 8478, 8522],
];

const GRADE_WORD = { 'Pre-Kindergarten': 'PRE-K', Kindergarten: 'KINDERGARTEN', 'Grade 1': 'GRADE 1', 'Grade 2': 'GRADE 2' };
const SUBJECT_WORD = { [QURAN]: 'QUR', [ARABIC]: 'ARABIC', [ISLAMIC]: 'ISLAMIC STUDIES' };
const HEADER = ['Week', 'Standard Code', 'Focus Skill', 'Objective', 'Learning Outcome'];
const WEEKS = 8;

function fail(message) {
  console.error(`build-al-razi-qai-split: ${message}`);
  process.exit(1);
}

function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

const raw = readFileSync(SOURCE);

if (sha256(raw) !== TXT_SHA256) {
  fail(`the source text's sha256 is ${sha256(raw)}, not the pinned source's ${TXT_SHA256}`);
}

// No trimming and no newline normalisation: a line is exactly the bytes between
// two "\n".
const lines = raw.toString('utf8').split('\n');
const line = (n) => lines[n - 1];

const tables = [];
const rows = [];

for (const [grade, subject, headingLine, heading, first, last] of TABLES) {
  if (line(headingLine) !== heading) {
    fail(`line ${headingLine} is ${JSON.stringify(line(headingLine))}, expected ${JSON.stringify(heading)}`);
  }

  if (!heading.includes(GRADE_WORD[grade]) || !heading.includes(SUBJECT_WORD[subject])) {
    fail(`heading ${JSON.stringify(heading)} does not name ${grade} / ${subject}`);
  }

  if (first !== headingLine + 2 || line(first - 1) !== 'Quarter Standards Table') {
    fail(`table at ${headingLine} is not followed by "Quarter Standards Table" then its header`);
  }

  HEADER.forEach((cell, i) => {
    if (line(first + i) !== cell) {
      fail(`line ${first + i} is ${JSON.stringify(line(first + i))}, expected header cell ${JSON.stringify(cell)}`);
    }
  });

  if (last !== first + 4 + WEEKS * 5) {
    fail(`table at ${headingLine} does not span ${WEEKS} weeks of 5 cells`);
  }

  tables.push({
    grade_label: grade,
    subject,
    heading_line: headingLine,
    heading,
    first_line: first,
    last_line: last,
  });

  for (let n = 1; n <= WEEKS; n++) {
    const at = first + 5 * n;
    const cells = [0, 1, 2, 3, 4].map((k) => line(at + k));

    if (cells[0] !== String(n)) {
      fail(`line ${at}: the Week cell is ${JSON.stringify(cells[0])}, expected ${n}`);
    }

    cells.forEach((cell, k) => {
      if (cell === '' || cell !== cell.trim()) {
        fail(`line ${at + k}: ${JSON.stringify(cell)} is empty or has leading or trailing whitespace`);
      }
    });

    rows.push({
      grade_label: grade,
      subject,
      week_no: n,
      quarter: 1,
      focus: cells[2],
      objective: cells[3],
      learning_outcome: cells[4],
      standard_code: cells[1],
      assessment_note: null,
      source_lines: {
        week_no: at,
        standard_code: at + 1,
        focus: at + 2,
        objective: at + 3,
        learning_outcome: at + 4,
      },
    });
  }
}

// The combined cells this replaces: the four grades' weeks 1-8. Each must exist
// in the base file, or the plan and the live guide disagree about what is there.
const base = JSON.parse(readFileSync(BASE, 'utf8'));
const baseCells = new Set(base.rows.map((r) => `${r.grade_label}\0${r.subject}\0${r.week_no}`));
const replaces = [];

for (const grade of ['Pre-Kindergarten', 'Kindergarten', 'Grade 1', 'Grade 2']) {
  for (let n = 1; n <= WEEKS; n++) {
    if (!baseCells.has(`${grade}\0${COMBINED}\0${n}`)) {
      fail(`the base guide has no combined cell for ${grade} week ${n}`);
    }

    replaces.push({ grade_label: grade, subject: COMBINED, week_no: n });
  }
}

const out = {
  source_label: 'Al-Razi Detailed Pacing Plan, Pre-K to Grade 2, Quarter 1 (school document of 2026-09-07)',
  for_masjid: { id: 14, name_contains: 'razi', org_type: 'school' },
  applies_after: 'al-razi-pacing-2026-27.json',
  source: {
    title: 'First Semester/ Quarter 1 suggested pacing for all subjects from Pre-k to Grade 2',
    document_date: '2026-09-07',
    docx_sha256: DOCX_SHA256,
    txt_path: 'database/curriculum/sources/al-razi-detailed-pacing-plan-2026-09-07.txt',
    txt_sha256: TXT_SHA256,
    generator: 'database/curriculum/tools/build-al-razi-qai-split.mjs',
    tables,
  },
  replaces,
  rows,
};

if (rows.length !== 96 || replaces.length !== 32) {
  fail(`expected 96 rows and 32 replaces, built ${rows.length} and ${replaces.length}`);
}

const text = JSON.stringify(out, null, 1) + '\n';
writeFileSync(OUT, text);
console.log(`wrote ${OUT}`);
console.log(`rows=${rows.length} replaces=${replaces.length} sha256=${sha256(Buffer.from(text, 'utf8'))}`);
