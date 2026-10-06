import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

for (const file of ['forms/FormEditView.vue', 'FormResponsesView.vue', 'OfferingsView.vue']) {
    test(`${file} calls the page section Sign-up Form`, () => {
        const source = readFileSync(new URL(`../views/dashboard/${file}`, import.meta.url), 'utf8');
        const template = source.slice(0, source.indexOf('<script'));
        const text = template.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
        assert.match(text, /Sign-up Form section/);
        assert.doesNotMatch(text, /(?:add|Add) a Form section/i);
    });
}
