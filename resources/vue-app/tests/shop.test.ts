/**
 * The shop's admin helpers (core/helpers/shop.ts, shop slice B3): dollars to cents and back, the
 * whole-list PUT payload, a 409 and a 422 told apart, what a pickup line is and may do, the CSV
 * request, and the picture checks. Money and error reading are the real ones the screens use.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { currencyExponent, formatMinor, parseMajorToMinor } from '../composables/useMinorUnits.ts';
import { serverFieldErrors, serverMessage } from '../core/helpers/serverMessage.ts';
import {
    blankProductForm, blankSaleFilters, blankVariantRow, buildProductRequest, checkImageFiles, classifyFailure, csvFilename, errorsFor,
    formatWhen, formFromProduct, imageHint, imageLimits, imageOrderBody, minorToMajorText, moveItem, parsePrice, parseSort, parseStock,
    priceLabel, productOptions, removalWarning, removedVariants, replaceSale, rowErrors, saleStatus, salesCsvPath, salesCsvRequest,
    salesListPath, salesQuery, sizeChips, sizeOptions, stockLine, summaryTotals, unplacedErrors,
} from '../core/helpers/shop.ts';

const usd = { exponent: currencyExponent('usd'), parse: (text: string) => parseMajorToMinor(text, 'usd') };
const reader = { message: serverMessage, fields: serverFieldErrors };

const variant = (over: Record<string, unknown> = {}) => ({
    id: 11, label: 'M', enabled: true, price_minor: null, effective_price_minor: 2500, stock: 20, sold_count: 12, held: 1, available: 7, sort: 0, ...over,
});

const product = (over: Record<string, unknown> = {}) => ({
    id: 5, name: 'Hoodie', slug: 'hoodie', category: 'Tops', description: null, base_price_minor: 2500, currency: 'usd', active: true, sort: 3,
    variants: [variant(), variant({ id: 12, label: 'L', price_minor: 2800, effective_price_minor: 2800, stock: null, available: null, sold_count: 0, held: 0, sort: 1 })],
    images: [], lock_version: 4, ...over,
});

const sale = (over: Record<string, unknown> = {}) => ({
    id: 1, order_id: 9, order_number: 'MEC-1', paid_at: '2026-10-08T23:04:00Z', buyer_name: 'Amira', buyer_email: 'a@example.org', buyer_phone: null,
    product_id: 5, variant_id: 11, product_name: 'Hoodie', variant_label: 'M', quantity: 1, unit_minor: 2500, total_minor: 2500, currency: 'usd',
    collected_at: null, collected_by: null, oversold: false, refunded: false, charge_flag: null, to_hand_out: true, ...over,
});

/** An axios-shaped failure, as the admin client raises one. */
const httpError = (status: number, data: unknown) => Object.assign(new Error(`Request failed with status code ${status}`), { isAxiosError: true, response: { status, data } });

// ------------------------------------------------------------------------------------ dollars and cents

test('a typed price becomes integer cents, from the digits and never a float', () => {
    assert.deepEqual(parsePrice('25', usd), { ok: true, minor: 2500 });
    assert.deepEqual(parsePrice('49.99', usd), { ok: true, minor: 4999 });
    assert.deepEqual(parsePrice('49.5', usd), { ok: true, minor: 4950 });
    assert.deepEqual(parsePrice('1,500.00', usd), { ok: true, minor: 150000 });
    assert.deepEqual(parsePrice('19.99', usd), { ok: true, minor: 1999 }, '19.99 * 100 is 1998.9999999999998 as a float');
});

test('a price that is empty, zero, negative, over-precise or not a number is refused with a reason', () => {
    for (const text of ['', '  ', '0', '0.00', '-5', '$25', '1e3', 'abc', '10.999']) {
        const result = parsePrice(text, usd);
        assert.equal(result.ok, false, JSON.stringify(text));
        assert.ok(!result.ok && result.reason.length > 0, JSON.stringify(text));
    }
});

test('cents go back into the input as exact dollars, and the round trip changes nothing', () => {
    assert.equal(minorToMajorText(2500, 2), '25.00');
    assert.equal(minorToMajorText(2999, 2), '29.99');
    assert.equal(minorToMajorText(5, 2), '0.05');
    assert.equal(minorToMajorText(0, 2), '0.00');
    assert.equal(minorToMajorText(1500, 0), '1500');
    assert.equal(minorToMajorText(-1, 2), '');
    assert.equal(minorToMajorText(1.5, 2), '');

    for (const cents of [1, 9, 10, 99, 100, 101, 1999, 2999, 4950, 99999999]) {
        const text = minorToMajorText(cents, 2);
        assert.deepEqual(parsePrice(text, usd), { ok: true, minor: cents }, `${cents} -> ${text}`);
    }
});

test('stock is a whole number, or empty for unlimited; the order is a whole number, empty for zero', () => {
    assert.deepEqual(parseStock(''), { ok: true, value: null });
    assert.deepEqual(parseStock(' 20 '), { ok: true, value: 20 });
    assert.deepEqual(parseStock('0'), { ok: true, value: 0 });
    for (const text of ['-1', '2.5', 'ten', '1e3', '99999999999999999999']) assert.equal(parseStock(text).ok, false, text);

    assert.deepEqual(parseSort(''), { ok: true, value: 0 });
    assert.deepEqual(parseSort('-3'), { ok: true, value: -3 });
    assert.equal(parseSort('1.5').ok, false);
});

// --------------------------------------------------------------------------- the whole-list PUT payload

test('an edit sends the whole list: ids kept, new rows without an id, numbers not strings, lock_version', () => {
    const loaded = product();
    const form = formFromProduct(loaded as any, 2);

    assert.equal(form.price, '25.00');
    assert.equal(form.variants[0].price, '', 'a size with no price of its own shows empty');
    assert.equal(form.variants[1].price, '28.00');
    assert.equal(form.variants[1].stock, '', 'unlimited shows empty');

    form.name = '  Hoodie  ';
    const added = blankVariantRow();
    added.label = 'XL';
    added.stock = '5';
    added.price = '30';
    form.variants.push(added);

    const built = buildProductRequest(form, usd, loaded.lock_version);
    assert.ok(built.ok);
    if (!built.ok) return;

    const { body } = built;
    assert.equal(body.name, 'Hoodie');
    assert.equal(body.base_price_minor, 2500);
    assert.equal(body.lock_version, 4);
    assert.equal(body.active, true);
    assert.equal(body.sort, 3);

    assert.deepEqual(body.variants, [
        { id: 11, label: 'M', enabled: true, price_minor: null, stock: 20, sort: 0 },
        { id: 12, label: 'L', enabled: true, price_minor: 2800, stock: null, sort: 1 },
        { label: 'XL', enabled: true, price_minor: 3000, stock: 5, sort: 2 },
    ]);
    assert.ok(!('id' in body.variants[2]), 'a new size has no id key at all');

    // After the JSON hop the numbers are still numbers.
    const wire = JSON.parse(JSON.stringify(body));
    assert.equal(typeof wire.base_price_minor, 'number');
    assert.equal(typeof wire.lock_version, 'number');
    for (const row of wire.variants) {
        assert.equal(typeof row.sort, 'number');
        assert.ok(row.price_minor === null || typeof row.price_minor === 'number');
        assert.ok(row.stock === null || typeof row.stock === 'number');
    }
});

test('a create sends no lock_version and no id; neither slug nor currency is ever sent', () => {
    const form = blankProductForm();
    form.name = 'Cap';
    form.price = '12.50';
    const row = blankVariantRow();
    row.label = 'One size';
    form.variants.push(row);

    const built = buildProductRequest(form, usd);
    assert.ok(built.ok);
    if (!built.ok) return;

    assert.ok(!('lock_version' in built.body));
    assert.ok(!('id' in built.body.variants[0]));
    assert.equal(built.body.base_price_minor, 1250);
    assert.ok(!('slug' in built.body));
    assert.ok(!('currency' in built.body));

    // Even an edit of a loaded product that carries both never echoes them back.
    const edit = buildProductRequest(formFromProduct(product() as any, 2), usd, 4);
    assert.ok(edit.ok);
    if (edit.ok) {
        assert.ok(!('slug' in edit.body));
        assert.ok(!('currency' in edit.body));
    }
});

test('a cleared own price and a cleared stock are sent as null, so clearing them is saved', () => {
    const form = formFromProduct(product() as any, 2);
    form.variants[1].price = '';
    form.variants[0].stock = '';
    form.category = '';
    form.description = '   ';

    const built = buildProductRequest(form, usd, 4);
    assert.ok(built.ok);
    if (!built.ok) return;

    assert.equal(built.body.variants[1].price_minor, null);
    assert.equal(built.body.variants[0].stock, null);
    assert.ok('price_minor' in built.body.variants[1] && 'stock' in built.body.variants[0], 'the keys are present, not left out');
    assert.equal(built.body.category, null);
    assert.equal(built.body.description, null);
});

test('reordering the rows saves the new places as `sort`, and each row keeps its id', () => {
    const form = formFromProduct(product() as any, 2);
    form.variants = moveItem(form.variants, 1, 0);

    const built = buildProductRequest(form, usd, 4);
    assert.ok(built.ok);
    if (!built.ok) return;

    assert.deepEqual(built.body.variants.map((row) => [row.id, row.sort]), [[12, 0], [11, 1]]);
});

test('what was typed wrong is reported under the same dotted keys the server uses, and nothing is built', () => {
    const form = blankProductForm();
    form.price = '12.999';
    const first = blankVariantRow();
    const second = blankVariantRow();
    second.label = 'M';
    second.price = 'free';
    second.stock = '-2';
    form.variants.push(first, second);

    const built = buildProductRequest(form, usd);
    assert.equal(built.ok, false);
    if (built.ok) return;

    assert.deepEqual(Object.keys(built.errors).sort(), ['base_price_minor', 'name', 'variants.0.label', 'variants.1.price_minor', 'variants.1.stock']);
    assert.equal(rowErrors(built.errors, 1).stock.length, 1);
    assert.equal(rowErrors(built.errors, 0).label.length, 1);
});

test('sizes the form no longer lists are the ones saving removes, and the warning names them', () => {
    const loaded = product();
    const form = formFromProduct(loaded as any, 2);
    assert.deepEqual(removedVariants(loaded.variants as any, form), []);

    form.variants = form.variants.filter((row) => row.id !== 12);
    form.variants.push(blankVariantRow());
    const removed = removedVariants(loaded.variants as any, form);
    assert.deepEqual(removed, [{ id: 12, label: 'L' }]);
    assert.match(removalWarning(removed), /removes this size.*"L".*Paid orders keep their own record of it/);
    assert.match(removalWarning([{ label: 'S' }, { label: 'M' }]), /these sizes.*"S", "M".*of them/);
    assert.deepEqual(removedVariants(undefined, form), []);
});

// ------------------------------------------------------------------------------- 409 and 422 handling

test('a 409 is a conflict that carries the server\'s message and offers Reload; it is never a validation failure', () => {
    const failure = classifyFailure(
        httpError(409, { status: 'failed', message: 'Somebody else changed this product. Reload it to see their changes.' }),
        'The product was not saved.',
        reader
    );

    assert.equal(failure.kind, 'conflict');
    assert.equal(failure.message, 'Somebody else changed this product. Reload it to see their changes.');
    assert.ok(!('fields' in failure));
});

test('a 422 carries its flat dotted keys, each placed beside its field or row, and the rest are not lost', () => {
    const failure = classifyFailure(
        httpError(422, {
            status: 'failed',
            data: {
                name: ['A product needs a name.'],
                'variants.0.label': ['Two sizes cannot share a name.'],
                'variants.2.label': ['This size is already in a basket or an order, so it cannot be renamed. Switch it off and add a new size.'],
                'variants.2.stock': ['Stock cannot be negative.'],
                lock_version: ['The version is out of date.'],
            },
        }),
        'The product was not saved.',
        reader
    );

    assert.equal(failure.kind, 'invalid');
    if (failure.kind !== 'invalid') return;

    assert.deepEqual(errorsFor(failure.fields, 'name'), ['A product needs a name.']);
    assert.deepEqual(rowErrors(failure.fields, 0).label, ['Two sizes cannot share a name.']);
    assert.match(rowErrors(failure.fields, 2).label[0], /switch it off and add a new size/i);
    assert.deepEqual(rowErrors(failure.fields, 2).stock, ['Stock cannot be negative.']);
    assert.deepEqual(rowErrors(failure.fields, 1).label, []);
    assert.deepEqual(unplacedErrors(failure.fields), ['The version is out of date.']);
});

test('403, 404 and a dropped connection are told apart, each in the server\'s words or ours', () => {
    const off = classifyFailure(httpError(403, { status: 'failed', message: 'Online shop is not switched on for this organisation.' }), 'x', reader);
    assert.deepEqual([off.kind, off.message], ['forbidden', 'Online shop is not switched on for this organisation.']);

    assert.equal(classifyFailure(httpError(404, { message: 'Not found.' }), 'x', reader).kind, 'not_found');

    const offline = classifyFailure(Object.assign(new Error('Network Error'), { isAxiosError: true }), 'The product was not saved.', reader);
    assert.deepEqual([offline.kind, offline.message], ['other', 'The product was not saved.']);
    assert.equal(classifyFailure(httpError(500, {}), 'Try again.', reader).kind, 'other');
});

// ------------------------------------------------------------------------------- the server's numbers

test('a size reads "Total 20 · sold 12 · in baskets 1 · left 7", and an unlimited one says Unlimited', () => {
    assert.equal(stockLine({ stock: 20, sold_count: 12, held: 1, available: 7 }), 'Total 20 · sold 12 · in baskets 1 · left 7');
    assert.equal(stockLine({ stock: null, sold_count: 3, held: 0, available: null }), 'Total unlimited · sold 3 · in baskets 0 · left Unlimited');
    assert.equal(stockLine({ stock: 5, sold_count: 5, held: 0, available: 0 }), 'Total 5 · sold 5 · in baskets 0 · left 0');
});

test('size chips are on, off or sold out; an unlimited size is never sold out', () => {
    const chips = sizeChips({
        variants: [
            variant({ id: 1, label: 'S' }),
            variant({ id: 2, label: 'M', enabled: false }),
            variant({ id: 3, label: 'L', available: 0 }),
            variant({ id: 4, label: 'XL', stock: null, available: null }),
        ] as any,
    });

    assert.deepEqual(chips.map((chip) => [chip.label, chip.state]), [['S', 'on'], ['M', 'off'], ['L', 'sold_out'], ['XL', 'on']]);
});

test('the list shows "from $X" only when the sizes a buyer can pick cost different amounts', () => {
    const format = (minor: number) => formatMinor(minor, 'usd');

    assert.equal(priceLabel(product() as any, format), 'from $25.00');
    assert.equal(priceLabel(product({ variants: [variant()] }) as any, format), '$25.00');
    assert.equal(priceLabel(product({ variants: [] }) as any, format), '$25.00');
    // A switched-off dearer size does not make the price "from".
    assert.equal(priceLabel(product({ variants: [variant(), variant({ id: 12, enabled: false, effective_price_minor: 2800 })] }) as any, format), '$25.00');
});

test('moving a row keeps the others in order and leaves the original list alone', () => {
    const list = ['a', 'b', 'c', 'd'];
    assert.deepEqual(moveItem(list, 0, 2), ['b', 'c', 'a', 'd']);
    assert.deepEqual(moveItem(list, 3, 0), ['d', 'a', 'b', 'c']);
    assert.deepEqual(moveItem(list, 1, 1), list);
    assert.deepEqual(moveItem(list, -1, 2), list);
    assert.deepEqual(moveItem(list, 1, 9), list);
    assert.deepEqual(list, ['a', 'b', 'c', 'd']);
});

// ------------------------------------------------------------------------------------------- pictures

test('the picture limits come from the answer, 10 MB when it names none', () => {
    assert.deepEqual(imageLimits({ currency: 'usd', max_images: 8, max_image_mb: 5 }), { maxImages: 8, maxMb: 5 });
    assert.deepEqual(imageLimits({ currency: 'usd', max_images: 8 }), { maxImages: 8, maxMb: 10 });
    assert.deepEqual(imageLimits(undefined), { maxImages: 8, maxMb: 10 });
    assert.match(imageHint({ maxImages: 8, maxMb: 10 }), /^Up to 8 pictures, 10 MB each/);
});

test('files are checked before they go: type, size, and how many the product has room for', () => {
    const limits = { maxImages: 8, maxMb: 10 };
    const mb = 1024 * 1024;

    assert.deepEqual(checkImageFiles([{ name: 'a.jpg', size: 2 * mb, type: 'image/jpeg' }, { name: 'b.WEBP', size: 10 * mb, type: 'image/webp' }], limits, 0), []);
    assert.match(checkImageFiles([{ name: 'big.png', size: 10 * mb + 1, type: 'image/png' }], limits, 0)[0], /big\.png: a picture can be at most 10 MB/);
    assert.match(checkImageFiles([{ name: 'x.heic', size: 1, type: 'image/heic' }], limits, 0)[0], /x\.heic: a picture must be a JPG, PNG, GIF or WebP/);
    assert.match(checkImageFiles([{ name: 'x.svg', size: 1, type: 'image/svg+xml' }], limits, 0)[0], /x\.svg/);
    assert.match(checkImageFiles([{ name: 'noext', size: 1, type: 'image/png' }], limits, 0)[0], /noext/);
    assert.deepEqual(checkImageFiles([{ name: 'a.png', size: 1, type: '' }], limits, 0), [], 'a browser that names no type is judged by the file name');
    assert.equal(checkImageFiles([], limits, 0)[0], 'Choose at least one picture.');

    const three = [1, 2, 3].map((n) => ({ name: `${n}.png`, size: 1, type: 'image/png' }));
    assert.match(checkImageFiles(three, limits, 6)[0], /at most 8 pictures; this one has 6 and you chose 3/);
    assert.deepEqual(checkImageFiles(three, limits, 5), []);
    assert.equal(checkImageFiles([{ name: 'big.png', size: 11 * mb, type: 'image/png' }, { name: 'x.gif', size: 1, type: 'image/bmp' }], limits, 0).length, 2, 'every bad file is named');
});

test('the reorder body lists every picture id, first to last', () => {
    assert.deepEqual(imageOrderBody([{ id: 4 }, { id: 9 }, { id: 2 }]), { order: [4, 9, 2] });
});

// ---------------------------------------------------------------------------------------- pickup rows

test('a plain paid line is to hand out, can be handed out, and is not collected', () => {
    const status = saleStatus(sale() as any);

    assert.equal(status.toHandOut, true);
    assert.equal(status.canCollect, true);
    assert.equal(status.canUndoCollect, false);
    assert.equal(status.oversoldOpen, false);
    assert.equal(status.state, 'to_hand_out');
    assert.deepEqual(status.badges, []);
});

test('a collected line can be undone and is no longer to hand out', () => {
    const status = saleStatus(sale({ collected_at: '2026-10-09T10:00:00Z', to_hand_out: false }) as any);

    assert.deepEqual([status.collected, status.toHandOut, status.canCollect, status.canUndoCollect, status.state], [true, false, false, true, 'collected']);
});

test('a refunded line is never handed out and says Refunded', () => {
    const status = saleStatus(sale({ refunded: true, charge_flag: 'refunded', to_hand_out: false }) as any);

    assert.deepEqual([status.toHandOut, status.canCollect, status.state], [false, false, 'do_not_hand_out']);
    assert.deepEqual(status.badges, [{ text: 'Refunded', tone: 'secondary' }]);
});

test('a disputed line says Disputed, once, and is never handed out', () => {
    const status = saleStatus(sale({ refunded: true, charge_flag: 'disputed', to_hand_out: false }) as any);

    assert.equal(status.canCollect, false);
    assert.equal(status.disputed, true);
    assert.deepEqual(status.badges, [{ text: 'Disputed', tone: 'danger' }], 'no "Refunded" beside it: no money went back');
});

test('a partly refunded line is still to hand out, with the Stripe warning', () => {
    const status = saleStatus(sale({ charge_flag: 'partially_refunded' }) as any);

    assert.deepEqual([status.toHandOut, status.canCollect, status.partlyRefunded], [true, true, true]);
    assert.deepEqual(status.badges, [{ text: 'Partly refunded: check Stripe before handing out', tone: 'warning' }]);
});

test('an oversold line with no decision is oversold-open, and offers the two decisions', () => {
    const status = saleStatus(sale({ oversold: true }) as any);

    assert.deepEqual([status.oversoldOpen, status.canResolve, status.canUndoResolve], [true, true, false]);
});

test('a decided, collected or refunded oversold line is not oversold-open', () => {
    assert.equal(saleStatus(sale({ oversold: true, resolution: 'substituted' }) as any).oversoldOpen, false);
    assert.equal(saleStatus(sale({ oversold: true, resolution: 'refunded' }) as any).oversoldOpen, false);
    assert.equal(saleStatus(sale({ oversold: true, collected_at: '2026-10-09T10:00:00Z', to_hand_out: false }) as any).oversoldOpen, false);
    assert.equal(saleStatus(sale({ oversold: true, refunded: true, charge_flag: 'refunded', to_hand_out: false }) as any).oversoldOpen, false);
    assert.equal(saleStatus(sale({ oversold: false }) as any).oversoldOpen, false);
});

test('resolved "refunded" is not to hand out; resolved "substituted" still is; either can be undone', () => {
    const refunded = saleStatus(sale({ oversold: true, resolution: 'refunded', resolved_at: '2026-10-09T10:00:00Z', to_hand_out: true }) as any);
    assert.deepEqual([refunded.toHandOut, refunded.canCollect, refunded.canUndoResolve, refunded.state], [false, false, true, 'do_not_hand_out']);
    assert.deepEqual(refunded.badges, [{ text: 'Oversold: refunded', tone: 'secondary' }]);

    const substituted = saleStatus(sale({ oversold: true, resolution: 'substituted' }) as any);
    assert.deepEqual([substituted.toHandOut, substituted.canCollect, substituted.canUndoResolve, substituted.state], [true, true, true, 'to_hand_out']);
    assert.deepEqual(substituted.badges, [{ text: 'Oversold: substituted', tone: 'info' }]);
});

test('a line from a backend that sends no to_hand_out is judged from what it does send', () => {
    const { to_hand_out: _omitted, ...row } = sale();

    assert.equal(saleStatus(row as any).toHandOut, true);
    assert.equal(saleStatus({ ...row, refunded: true } as any).toHandOut, false);
    assert.equal(saleStatus({ ...row, collected_at: '2026-10-09T10:00:00Z' } as any).toHandOut, false);
});

test('an answer for one line replaces that line and no other', () => {
    const rows = [sale({ id: 1 }), sale({ id: 2 }), sale({ id: 3 })] as any[];
    const out = replaceSale(rows, sale({ id: 2, collected_at: '2026-10-09T10:00:00Z', to_hand_out: false }) as any);

    assert.deepEqual(out.map((row) => !!row.collected_at), [false, true, false]);
    assert.equal(rows[1].collected_at, null, 'the old list is untouched');
});

// ---------------------------------------------------------------------------- the header and the filters

const summary = [
    { product_id: 5, variant_id: 11, product_name: 'Hoodie', variant_label: 'M', to_hand_out: 4, collected: 2, oversold_open: 1 },
    { product_id: 5, variant_id: 12, product_name: 'Hoodie', variant_label: 'L', to_hand_out: 1, collected: 0, oversold_open: 0 },
    { product_id: 6, variant_id: 21, product_name: 'Cap', variant_label: 'One size', to_hand_out: 3, collected: 5, oversold_open: 2 },
];

test('the header totals add the server\'s own rows, and the filters offer each product and size once', () => {
    assert.deepEqual(summaryTotals(summary), { to_hand_out: 8, collected: 7, oversold_open: 3 });
    assert.deepEqual(summaryTotals([]), { to_hand_out: 0, collected: 0, oversold_open: 0 });

    assert.deepEqual(productOptions(summary), [{ id: 5, name: 'Hoodie' }, { id: 6, name: 'Cap' }]);
    assert.deepEqual(sizeOptions(summary, 5), [{ id: 11, label: 'M' }, { id: 12, label: 'L' }]);
    assert.deepEqual(sizeOptions(summary, ''), [
        { id: 11, label: 'Hoodie: M' }, { id: 12, label: 'Hoodie: L' }, { id: 21, label: 'Cap: One size' },
    ]);
    // A product renamed after it sold is two header lines for one size: one entry.
    assert.equal(sizeOptions([...summary, { ...summary[0], product_name: 'Hoodie (old)' }], 5).length, 2);
});

// ------------------------------------------------------------------------------------------ the CSV

test('the list request names the state, the filters and the page; blanks are left out', () => {
    const filters = { ...blankSaleFilters(), state: 'collected' as const, product_id: 5, variant_id: 11, search: '  Amira  ' };

    assert.equal(salesQuery(blankSaleFilters()), 'state=to_hand_out');
    assert.equal(salesQuery(filters), 'state=collected&product_id=5&variant_id=11&search=Amira');
    assert.equal(salesQuery(filters, 2, 50), 'state=collected&product_id=5&variant_id=11&search=Amira&per_page=50&page=2');
    assert.equal(salesListPath(3, blankSaleFilters(), 1), '/api/admin/masjids/3/shop/sales?state=to_hand_out&page=1');
});

test('the CSV is a bearer-token fetch of sales.csv with the current filters and no paging', () => {
    const filters = { state: 'all' as const, product_id: 5, variant_id: '' as const, search: 'MEC-10 & co' };
    const request = salesCsvRequest(3, filters, 'tok123');

    assert.equal(request.url, '/api/admin/masjids/3/shop/sales.csv?state=all&product_id=5&search=MEC-10+%26+co');
    assert.deepEqual(request.init.headers, { Authorization: 'Bearer tok123', Accept: 'text/csv' });
    assert.equal(salesCsvPath(3, blankSaleFilters()), '/api/admin/masjids/3/shop/sales.csv?state=to_hand_out');

    const params = new URL(request.url, 'https://example.test').searchParams;
    assert.equal(params.has('page'), false);
    assert.equal(params.has('per_page'), false);
    assert.equal(params.get('search'), 'MEC-10 & co');

    // The list and the file are one set of filters: the CSV query is the list's, less the page.
    assert.equal(salesQuery(filters, 4, 25).replace(/&per_page=25&page=4$/, ''), new URL(request.url, 'https://example.test').search.slice(1));
});

test('the download is named as the server named it, or by the date', () => {
    assert.equal(csvFilename('attachment; filename="shop-sales-mec-2026-10-08.csv"', '2026-10-09'), 'shop-sales-mec-2026-10-08.csv');
    assert.equal(csvFilename(null, '2026-10-09'), 'shop-sales-2026-10-09.csv');
    assert.equal(csvFilename('inline', '2026-10-09'), 'shop-sales-2026-10-09.csv');
});

// --------------------------------------------------------------------------------------------- time

test('paid-at is shown on the organisation\'s clock, with the zone, and survives a zone the browser does not know', () => {
    const iso = '2026-10-08T23:04:00Z';

    assert.match(formatWhen(iso, 'America/New_York'), /(7:04|19:04)/);
    assert.match(formatWhen(iso, 'America/New_York'), /EDT/);
    assert.match(formatWhen(iso, 'Asia/Tokyo'), /(8:04|08:04)/, 'the same instant is the next morning in Tokyo');
    assert.ok(formatWhen(iso, 'Not/AZone').length > 0, 'a bad zone falls back to the browser clock');
    assert.ok(formatWhen(iso, null).length > 0);
    assert.equal(formatWhen(null, 'America/New_York'), '—');
    assert.equal(formatWhen('not a date', 'America/New_York'), 'not a date');
});
