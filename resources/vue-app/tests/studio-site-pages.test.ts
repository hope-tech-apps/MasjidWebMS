/**
 * The website mockup's menu and footer (core/studio/sitePages.ts) list only the
 * pages the live site serves: a page planned inactive is left out, as
 * PagesController leaves it out. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buttonPages, menuPages } from '../core/studio/sitePages.ts';

const page = (slug: string, extra: Record<string, unknown> = {}) => ({
    slug,
    title: `Title ${slug}`,
    order: 0,
    is_active: true,
    show_in_menu: true,
    show_as_button: false,
    meta_description: null,
    sections: [],
    ...extra,
});

test('a page planned inactive is in neither the menu nor the footer list', () => {
    const pages = [page('home'), page('waiting', { is_active: false }), page('about')];
    assert.deepEqual(menuPages(pages).map((p) => p.slug), ['home', 'about']);
});

test('menu pages keep the planned order, leave out hidden pages, and leave the button to the button', () => {
    const pages = [page('b'), page('hidden', { show_in_menu: false }), page('cta', { show_as_button: true }), page('a')];
    assert.deepEqual(menuPages(pages).map((p) => p.slug), ['b', 'a']);
    assert.deepEqual(buttonPages(pages).map((p) => p.slug), ['cta']);
});

test('an inactive page is never a button either', () => {
    assert.deepEqual(buttonPages([page('cta', { show_as_button: true, is_active: false })]), []);
});
