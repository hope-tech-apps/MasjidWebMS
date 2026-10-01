/**
 * The shop section editor's helpers (core/helpers/shopSection.ts), which must agree with what
 * the server accepts (App\Http\Requests\Concerns\ValidatesShopSection) so the editor never
 * offers a value the save would refuse. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    SHOP_CATEGORY_MAX,
    SHOP_DEFAULT_MAX_ITEMS,
    SHOP_HEADING_MAX,
    SHOP_MAX_ITEMS,
    SHOP_MIN_ITEMS,
    clampShopMaxItems,
    shopCategoriesFrom,
} from '../core/helpers/shopSection.ts';

test('the bounds are the server\'s: heading 120, category 60, 1 to 24 products, 8 by default', () => {
    assert.equal(SHOP_HEADING_MAX, 120);
    assert.equal(SHOP_CATEGORY_MAX, 60);
    assert.equal(SHOP_MIN_ITEMS, 1);
    assert.equal(SHOP_MAX_ITEMS, 24);
    assert.equal(SHOP_DEFAULT_MAX_ITEMS, 8);
});

test('how many to show is a whole number from 1 to 24: out-of-range values are pulled in', () => {
    assert.equal(clampShopMaxItems(1), 1);
    assert.equal(clampShopMaxItems(24), 24);
    assert.equal(clampShopMaxItems(0), 1);
    assert.equal(clampShopMaxItems(-5), 1);
    assert.equal(clampShopMaxItems(25), 24);
    assert.equal(clampShopMaxItems(1000), 24);
    assert.equal(clampShopMaxItems(7.9), 7);
});

test('anything that is not a number is the default, and a numeric string is read as its number', () => {
    for (const value of [undefined, null, '', '  ', 'many', NaN, Infinity, {}, [], true]) {
        assert.equal(clampShopMaxItems(value), 8, String(value));
    }
    assert.equal(clampShopMaxItems('12'), 12);
});

test('categories are the distinct non-empty ones from the products, sorted, kept exactly as stored', () => {
    const body = {
        status: 'success',
        data: [
            { id: 1, category: 'Uniforms' },
            { id: 2, category: 'Books' },
            { id: 3, category: 'Uniforms' },
            { id: 4, category: null },
            { id: 5, category: '' },
            { id: 6, category: '   ' },
            { id: 7 },
            { id: 8, category: 'Uniforms ' },
        ],
    };

    // 'Uniforms ' differs from 'Uniforms' as the renderer compares them, so both are kept as stored.
    assert.deepEqual(shopCategoriesFrom(body), ['Books', 'Uniforms', 'Uniforms ']);
});

test('a paginated list is read the same way', () => {
    const body = { status: 'success', data: { current_page: 1, data: [{ category: 'Gifts' }, { category: 'Apparel' }] } };

    assert.deepEqual(shopCategoriesFrom(body), ['Apparel', 'Gifts']);
});

test('a shop with products but no categories is a real answer: an empty list, not unavailable', () => {
    assert.deepEqual(shopCategoriesFrom({ data: [{ category: null }, { category: '' }] }), []);
    assert.deepEqual(shopCategoriesFrom({ data: [] }), []);
});

test('a body with no product list at all is unavailable, so the editor falls back to a text input', () => {
    for (const body of [undefined, null, {}, { data: null }, { data: 'nope' }, { data: { total: 0 } }, 'x', 7]) {
        assert.equal(shopCategoriesFrom(body), null, JSON.stringify(body));
    }
});

test('a category the server would refuse to save (over 60 characters) is not offered', () => {
    const tooLong = 'x'.repeat(SHOP_CATEGORY_MAX + 1);
    const atLimit = 'y'.repeat(SHOP_CATEGORY_MAX);

    assert.deepEqual(shopCategoriesFrom({ data: [{ category: tooLong }, { category: atLimit }] }), [atLimit]);
});
