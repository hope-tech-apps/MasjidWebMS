/**
 * The "Edited" mark a parent sees is ONE word per language, whether it sits on a class
 * story or on a message. The two were built separately and two languages ended up with
 * two different words for the same mark.
 *
 * Read as source text: the locale tables are plain objects, and this pins the pairs
 * without loading the family screens.
 *
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const family = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'views', 'family');
const tables = ['familyI18n.ts', 'locales/es.ts', 'locales/fa-AF.ts', 'locales/ps.ts', 'locales/ur.ts']
    .map((file) => readFileSync(path.join(family, file), 'utf8')).join('\n');

const values = (key: string): string[] =>
    [...tables.matchAll(new RegExp(`\\b${key}: "([^"]+)"`, 'g'))].map((match) => match[1]);

test('the Edited mark is the same word on a story and on a message, in all six languages', () => {
    const story = values('story_edited');
    const message = values('msg_edited');

    assert.equal(story.length, 6, 'story_edited is in every locale table');
    assert.equal(message.length, 6, 'msg_edited is in every locale table');
    assert.deepEqual([...story].sort(), [...message].sort());
    // Six languages, six different words: none was left in English by mistake.
    assert.equal(new Set(story).size, 6);
});
