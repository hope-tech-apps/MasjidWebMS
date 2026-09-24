/**
 * The ARIA tabs keys (core/helpers/tabKeys.ts) Studio's preview tabs answer:
 * arrows wrap, Home and End jump, anything else is left alone.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { tabIndexForKey } from '../core/helpers/tabKeys.ts';

test('Right and Left move one tab and wrap at the ends', () => {
    assert.equal(tabIndexForKey('ArrowRight', 0, 3), 1);
    assert.equal(tabIndexForKey('ArrowRight', 2, 3), 0);
    assert.equal(tabIndexForKey('ArrowLeft', 1, 3), 0);
    assert.equal(tabIndexForKey('ArrowLeft', 0, 3), 2);
});

test('Home and End go to the first and last tab', () => {
    assert.equal(tabIndexForKey('Home', 2, 4), 0);
    assert.equal(tabIndexForKey('End', 0, 4), 3);
});

test('other keys, and no tabs, move nothing', () => {
    assert.equal(tabIndexForKey('Enter', 1, 3), null);
    assert.equal(tabIndexForKey('Tab', 1, 3), null);
    assert.equal(tabIndexForKey('ArrowRight', 0, 0), null);
});
