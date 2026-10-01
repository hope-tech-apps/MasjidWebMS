/**
 * The shop's three admin screens, MOUNTED (shop slice B3): each one is compiled from its .vue file and
 * driven with the API answering what the server answers (tests/support/mountSfc.ts says how, with no DOM).
 *
 * A helper test can say a payload is right; only a mounted one can say the screen SENDS it, that a 409
 * shows Reload and retries nothing, that a refunded line offers no Hand out, and that an oversold one
 * shows its banner.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import * as minor from '../composables/useMinorUnits.ts';
import * as shop from '../core/helpers/shop.ts';
import { serverFieldErrors, serverMessage } from '../core/helpers/serverMessage.ts';
import { click, deferred, flush, httpError, mountSfc, submit, type } from './support/mountSfc.ts';
import type { Mounted, Node } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');

// ------------------------------------------------------------------------------------------ fixtures

const variant = (over: Record<string, unknown> = {}) => ({
    id: 11, label: 'M', enabled: true, price_minor: null, effective_price_minor: 2500, stock: 20, sold_count: 12, held: 1, available: 7, sort: 0, ...over,
});

const product = (over: Record<string, unknown> = {}) => ({
    id: 5, name: 'Hoodie', slug: 'hoodie', category: 'Tops', description: null, base_price_minor: 2500, currency: 'usd', active: true, sort: 3,
    variants: [variant(), variant({ id: 12, label: 'L', price_minor: 2800, effective_price_minor: 2800, stock: 5, sold_count: 5, held: 0, available: 0, sort: 1 })],
    images: [], lock_version: 4, ...over,
});

const picture = (id: number) => ({ id, url: `https://cdn.example/${id}.jpg`, name: `p${id}`, file_name: `p${id}.jpg`, mime_type: 'image/jpeg', size: 100, order: id });

const sale = (over: Record<string, unknown> = {}) => ({
    id: 1, order_id: 9, order_number: 'MEC-1', paid_at: '2026-10-08T23:04:00Z', buyer_name: 'Amira', buyer_email: 'a@example.org', buyer_phone: '555-0101',
    product_id: 5, variant_id: 11, product_name: 'Hoodie', variant_label: 'M', quantity: 1, unit_minor: 2500, total_minor: 2500, currency: 'usd',
    collected_at: null, collected_by: null, oversold: false, refunded: false, charge_flag: null, to_hand_out: true, resolution: null, ...over,
});

const summaryRows = [
    { product_id: 5, variant_id: 11, product_name: 'Hoodie', variant_label: 'M', to_hand_out: 4, collected: 2, oversold_open: 1 },
    { product_id: 5, variant_id: 12, product_name: 'Hoodie', variant_label: 'L', to_hand_out: 1, collected: 0, oversold_open: 0 },
];

const pageOf = (rows: any[]) => ({ data: rows, current_page: 1, last_page: 1, per_page: 25, total: rows.length });
const meta = { currency: 'usd', max_images: 8, max_image_mb: 10 };

// ------------------------------------------------------------------------------------------- doubles

/** The sweetalert2 stand-in: records every dialog, and answers a confirm as the test scripted. */
function swalDouble() {
    const dialogs: any[] = [];
    const double = { confirm: true, dialogs, default: { fire: async (options: any) => { dialogs.push(options); return { isConfirmed: double.confirm }; } } };

    return double;
}

/** PageDataContainer without its card: the title, the header slot, the header button and the body. */
const containerStub = {
    props: ['title', 'hideButton', 'buttonProps', 'paginationOptions'],
    emits: ['headerButtonClick', 'pageChange'],
    render(this: any) {
        return vue.h('div', [
            vue.h('h1', this.title),
            this.$slots.headerButtons?.(),
            this.hideButton ? null : vue.h('button', { onClick: () => this.$emit('headerButtonClick') }, this.buttonProps?.title),
            this.$slots.default?.(),
        ]);
    },
};
const tabsStub = { props: ['active'], render: () => null };

function routerDouble(params: Record<string, any> = {}) {
    const route = vue.reactive({ params });
    const calls: any[] = [];
    const router = { push: async (to: any) => { calls.push(['push', to]); }, replace: async (to: any) => { calls.push(['replace', to]); } };

    return { route, router, calls, module: { useRoute: () => route, useRouter: () => router, RouterView: { render: () => null } } };
}

const masjidStoreModule = { useMasjidStore: () => ({ masjid: { id: 3, name: 'MEC', timezone: 'America/New_York' } }) };

/** The shop store with its state reactive and its calls recorded; each test scripts what a call answers. */
function shopStoreDouble(script: Record<string, (...args: any[]) => any> = {}) {
    const calls: Array<{ name: string; args: any[] }> = [];
    const store: any = vue.reactive({ productsPaginated: undefined, productsMeta: undefined, salesPaginated: undefined, salesMeta: undefined });

    const record = (name: string, fallback: (...args: any[]) => any) => (...args: any[]) => {
        calls.push({ name, args });

        return (script[name] ?? fallback)(...args);
    };

    Object.assign(store, {
        fetchProducts: record('fetchProducts', async () => { store.productsPaginated = pageOf([product()]); store.productsMeta = meta; }),
        fetchProduct: record('fetchProduct', async () => { store.productsMeta = meta; return { product: product(), meta }; }),
        createProduct: record('createProduct', async () => ({ product: product({ id: 7 }), meta })),
        updateProduct: record('updateProduct', async () => ({ product: product({ lock_version: 5 }), meta })),
        deleteProduct: record('deleteProduct', async () => undefined),
        uploadImages: record('uploadImages', async () => product({ images: [picture(1)], lock_version: 6 })),
        reorderImages: record('reorderImages', async () => product()),
        deleteImage: record('deleteImage', async () => product()),
        fetchSales: record('fetchSales', async () => { store.salesPaginated = pageOf([sale()]); store.salesMeta = { summary: summaryRows }; }),
        collectSale: record('collectSale', async () => sale({ collected_at: '2026-10-09T10:00:00Z', to_hand_out: false })),
        uncollectSale: record('uncollectSale', async () => sale()),
        resolveSale: record('resolveSale', async () => sale()),
        unresolveSale: record('unresolveSale', async () => sale()),
        exportSalesCsv: record('exportSalesCsv', async () => undefined),
    });

    return { store, calls, module: { useShopStore: () => store, shopErrorReader: { message: serverMessage, fields: serverFieldErrors } } };
}

const byId = (screen: Mounted, id: string): Node => {
    const found = screen.all((n) => n.props.id === id);
    if (found.length !== 1) throw new Error(`${found.length} elements have id ${id}`);

    return found[0];
};

const calledWith = (calls: Array<{ name: string; args: any[] }>, name: string) => calls.filter((call) => call.name === name);

/** A change event on a checkbox, as the browser raises it. */
const change = (el: Node, target: Record<string, any>) => el.props.onChange({ target, currentTarget: target, preventDefault() {}, stopPropagation() {} });

// ------------------------------------------------------------------------------------------- products

async function mountProducts(storeDouble = shopStoreDouble(), swal = swalDouble(), router = routerDouble()) {
    const screen = await mountSfc('views/dashboard/shop/ShopProductsView.vue', {}, {
        'vue-router': router.module,
        sweetalert2: swal,
        '@/components/PageDataContainer.vue': { default: containerStub },
        './ShopTabs.vue': { default: tabsStub },
        '@/composables/useMinorUnits': minor,
        '@/core/helpers/shop': shop,
        '@/core/types/elements/Buttons': {},
        '@/core/types/elements/Pagination': {},
        '@/stores/masjid/shopStore': storeDouble.module,
        '@/stores/masjidStore': masjidStoreModule,
    });
    await flush();

    return { screen, ...storeDouble, swal, router };
}

test('products: a row shows its price as "from $X", each size as a chip, and an off product says so', async () => {
    const double = shopStoreDouble({
        fetchProducts: async () => {
            double.store.productsMeta = meta;
            double.store.productsPaginated = pageOf([
                product(),
                product({ id: 6, name: 'Cap', active: false, variants: [variant({ id: 21, label: 'One size', enabled: false })], images: [picture(3)] }),
            ]);
        },
    });
    const { screen } = await mountProducts(double);

    const text = screen.text();
    assert.match(text, /Hoodie/);
    assert.match(text, /from \$25\.00/, 'the sizes cost different amounts');
    assert.match(text, /M/);
    assert.match(text, /L · sold out/, 'a size with nothing left');
    assert.match(text, /One size · off/, 'a size switched off');
    assert.match(text, /Cap Off/, 'a product switched off');
    assert.equal(screen.all((n) => n.tag === 'img').length, 1, 'only the product with a picture has a thumbnail');
    screen.unmount();
});

test('products: the on/off switch saves only `active` with the lock_version the row was loaded with', async () => {
    const { screen, calls } = await mountProducts();

    const toggle = byId(screen, 'shop-active-5');
    change(toggle, { checked: false });
    await flush();

    const update = calledWith(calls, 'updateProduct');
    assert.equal(update.length, 1);
    assert.deepEqual(update[0].args, [5, { active: false, lock_version: 4 }]);
    screen.unmount();
});

test('products: a 409 on the switch says somebody else changed it, and the list is read again, not the save retried', async () => {
    const { screen, calls, swal } = await mountProducts(shopStoreDouble({
        updateProduct: () => Promise.reject(httpError(409, { status: 'failed', message: 'Somebody else changed this product.' })),
    }));

    change(byId(screen, 'shop-active-5'), { checked: false });
    await flush();

    assert.equal(calledWith(calls, 'updateProduct').length, 1, 'no retry');
    assert.equal(calledWith(calls, 'fetchProducts').length, 2, 'the page was read again');
    assert.equal(swal.dialogs.at(-1).text, 'Somebody else changed this product.');
    screen.unmount();
});

test('products: Delete asks first, says paid orders keep their record, and deletes only on a yes', async () => {
    const swal = swalDouble();
    swal.confirm = false;
    const { screen, calls } = await mountProducts(shopStoreDouble(), swal);

    click(screen.button('Delete'));
    await flush();
    assert.match(swal.dialogs[0].text, /Paid orders keep their own record/);
    assert.equal(calledWith(calls, 'deleteProduct').length, 0, 'declined: nothing deleted');

    swal.confirm = true;
    click(screen.button('Delete'));
    await flush();
    assert.deepEqual(calledWith(calls, 'deleteProduct').map((call) => call.args), [[5]]);
    screen.unmount();
});

test('products: the shop switched off says so in the server\'s words and offers no Retry', async () => {
    const { screen } = await mountProducts(shopStoreDouble({
        fetchProducts: () => Promise.reject(httpError(403, { status: 'failed', message: 'Online shop is not switched on for this organisation.' })),
    }));

    assert.match(screen.text(), /Online shop is not switched on for this organisation\./);
    assert.equal(screen.all((n) => n.tag === 'button' && n.textContent.includes('Retry')).length, 0);
    screen.unmount();
});

test('products: an empty shop says so and offers the first product', async () => {
    const double = shopStoreDouble({ fetchProducts: async () => { double.store.productsMeta = meta; double.store.productsPaginated = pageOf([]); } });
    const { screen, router } = await mountProducts(double);

    assert.match(screen.text(), /No products yet/);
    click(screen.button('Add the first product'));
    assert.deepEqual(router.calls[0], ['push', { name: 'masjid.shop.productNew' }]);
    screen.unmount();
});

// -------------------------------------------------------------------------------------------- editor

async function mountEditor(params: Record<string, any>, storeDouble = shopStoreDouble(), swal = swalDouble()) {
    const router = routerDouble(params);
    const screen = await mountSfc('views/dashboard/shop/ShopProductEditorView.vue', {}, {
        'vue-router': router.module,
        sweetalert2: swal,
        '@/components/PageDataContainer.vue': { default: containerStub },
        './ShopTabs.vue': { default: tabsStub },
        '@/composables/useMinorUnits': minor,
        '@/core/helpers/shop': shop,
        '@/stores/masjid/shopStore': storeDouble.module,
    });
    await flush();

    return { screen, ...storeDouble, swal, router };
}

const sizeInputs = (screen: Mounted, prefix: string) => screen.all((n) => typeof n.props.id === 'string' && n.props.id.startsWith(prefix));

test('editor: a saved size shows the server\'s "Total 20 · sold 12 · in baskets 1 · left 7" and the price in dollars', async () => {
    const { screen } = await mountEditor({ productId: '5' });

    assert.match(screen.text(), /Total 20 · sold 12 · in baskets 1 · left 7/);
    assert.match(screen.text(), /Total 5 · sold 5 · in baskets 0 · left 0/);
    assert.equal(byId(screen, 'shop-price').value, '25.00');
    assert.equal(sizeInputs(screen, 'size-price-row-')[1].value, '28.00', 'the second size has its own price');
    assert.equal(sizeInputs(screen, 'size-stock-row-').length, 2);
    screen.unmount();
});

test('editor: Save sends the whole list, ids kept, numbers not strings, and the lock_version it loaded', async () => {
    const { screen, calls } = await mountEditor({ productId: '5' });

    type(byId(screen, 'shop-price'), '26.50');
    type(sizeInputs(screen, 'size-stock-row-')[0], '30');
    click(screen.button('Add a size'));
    await flush();
    type(sizeInputs(screen, 'size-label-row-')[2], 'XL');
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();

    const update = calledWith(calls, 'updateProduct');
    assert.equal(update.length, 1);
    const [id, body] = update[0].args;
    assert.equal(id, 5);
    assert.equal(body.base_price_minor, 2650);
    assert.equal(body.lock_version, 4);
    assert.deepEqual(body.variants.map((row: any) => [row.id ?? null, row.label, row.stock, row.sort]), [[11, 'M', 30, 0], [12, 'L', 5, 1], [null, 'XL', null, 2]]);
    assert.ok(!('slug' in body) && !('currency' in body));
    screen.unmount();
});

test('editor: a 409 shows the message with Reload, retries nothing, and Reload refetches and drops the stale form', async () => {
    let reads = 0;
    const { screen, calls } = await mountEditor({ productId: '5' }, shopStoreDouble({
        updateProduct: () => Promise.reject(httpError(409, { status: 'failed', message: 'Somebody else changed this product.' })),
        fetchProduct: async () => { reads += 1; return { product: product({ name: reads === 1 ? 'Hoodie' : 'Hoodie v2', lock_version: reads === 1 ? 4 : 9 }), meta }; },
    }));

    type(byId(screen, 'shop-name'), 'My edit');
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();

    assert.match(screen.text(), /Somebody else changed this product\./);
    assert.equal(calledWith(calls, 'updateProduct').length, 1, 'never auto-retried');
    assert.equal(byId(screen, 'shop-name').value, 'My edit', 'the stale form is still on screen until Reload');

    click(screen.button('Reload'));
    await flush();

    assert.equal(calledWith(calls, 'fetchProduct').length, 2, 'refetched');
    assert.equal(byId(screen, 'shop-name').value, 'Hoodie v2', 'the stale form is gone');
    assert.doesNotMatch(screen.text(), /Somebody else changed this product\./);

    // The next save carries the NEW lock_version.
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();
    assert.equal(calledWith(calls, 'updateProduct')[1].args[1].lock_version, 9);
    screen.unmount();
});

test('editor: a 422 puts each message beside its field or row, the frozen-name sentence included', async () => {
    const frozen = 'This size is already in a basket or an order, so it cannot be renamed. Switch it off and add a new size.';
    const { screen } = await mountEditor({ productId: '5' }, shopStoreDouble({
        updateProduct: () => Promise.reject(httpError(422, {
            status: 'failed',
            data: { name: ['A product needs a name.'], 'variants.0.label': [frozen], 'variants.1.label': ['Two sizes cannot share a name.'], 'variants.1.stock': ['Stock cannot be negative.'] },
        })),
    }));

    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();

    assert.equal(byId(screen, 'shop-name-error').textContent, 'A product needs a name.');
    const rows = screen.all((n) => n.props['data-test'] === 'size-row');
    assert.match(rows[0].textContent, /switch it off and add a new size/i);
    assert.match(rows[1].textContent, /Two sizes cannot share a name\./);
    assert.match(rows[1].textContent, /Stock cannot be negative\./);
    assert.doesNotMatch(rows[0].textContent, /Two sizes cannot share/);
    screen.unmount();
});

test('editor: what is typed wrong never leaves the browser, and is marked where it is', async () => {
    const { screen, calls } = await mountEditor({ productId: '5' });

    type(byId(screen, 'shop-price'), '12.999');
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();

    assert.equal(calledWith(calls, 'updateProduct').length, 0);
    assert.match(byId(screen, 'shop-price-error').textContent, /decimal place/);
    assert.equal(byId(screen, 'shop-price').props['aria-invalid'], 'true');
    screen.unmount();
});

test('editor: taking a size out asks first and names it; a "go back" saves nothing', async () => {
    const swal = swalDouble();
    swal.confirm = false;
    const { screen, calls } = await mountEditor({ productId: '5' }, shopStoreDouble(), swal);

    click(screen.all((n) => n.tag === 'button' && n.props['aria-label'] === 'Remove L')[0]);
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();

    assert.match(swal.dialogs[0].text, /removes this size.*"L"/);
    assert.equal(calledWith(calls, 'updateProduct').length, 0, 'declined: nothing saved');

    swal.confirm = true;
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();
    const body = calledWith(calls, 'updateProduct')[0].args[1];
    assert.deepEqual(body.variants.map((row: any) => row.id), [11], 'the omitted size is left out of the whole list');
    screen.unmount();
});

test('editor: a new product has no picture section; creating it moves to its edit address and the section appears', async () => {
    const created = product({ id: 7, name: 'Cap' });
    const { screen, calls, router } = await mountEditor({}, shopStoreDouble({ createProduct: async () => ({ product: created, meta }) }));

    assert.match(screen.text(), /Pictures can be added once the product has been created/);
    assert.equal(screen.all((n) => n.props['data-test'] === 'pictures').length, 0);
    // The shop's currency is read before the first price is typed.
    assert.equal(calledWith(calls, 'fetchProducts').length, 1);

    type(byId(screen, 'shop-name'), 'Cap');
    type(byId(screen, 'shop-price'), '12.50');
    click(screen.button('Add a size'));
    await flush();
    type(sizeInputs(screen, 'size-label-row-')[0], 'One size');
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();

    const body = calledWith(calls, 'createProduct')[0].args[0];
    assert.equal(body.base_price_minor, 1250);
    assert.ok(!('lock_version' in body));
    assert.deepEqual(router.calls.at(-1), ['replace', { name: 'masjid.shop.productEdit', params: { productId: 7 } }]);
    assert.equal(screen.all((n) => n.props['data-test'] === 'pictures').length, 1, 'the picture section appears');
    screen.unmount();
});

test('editor: the picture hint names the limits, and a file over the size or the count is refused before it goes up', async () => {
    const { screen, calls } = await mountEditor({ productId: '5' });

    assert.match(screen.text(), /Up to 8 pictures, 10 MB each/);

    const input = byId(screen, 'shop-picture-input');
    const mb = 1024 * 1024;
    await input.props.onChange({ target: { files: [{ name: 'big.jpg', size: 11 * mb, type: 'image/jpeg' }], value: 'x' } });
    await flush();

    assert.equal(calledWith(calls, 'uploadImages').length, 0, 'nothing is sent');
    assert.match(screen.all((n) => n.props['data-test'] === 'picture-problems')[0].textContent, /big\.jpg: a picture can be at most 10 MB/);
    screen.unmount();
});

test('editor: an upload that is fine goes up, and the answer (the whole product) replaces the pictures and the lock_version', async () => {
    // Loaded at version 4; this editor's own picture change moves it on by exactly one.
    const { screen, calls } = await mountEditor({ productId: '5' }, shopStoreDouble({
        uploadImages: async () => product({ images: [picture(1), picture(2)], lock_version: 5 }),
    }));

    const file = { name: 'a.jpg', size: 1000, type: 'image/jpeg' };
    await byId(screen, 'shop-picture-input').props.onChange({ target: { files: [file], value: '' } });
    await flush();

    assert.deepEqual(calledWith(calls, 'uploadImages')[0].args, [5, [file]]);
    assert.equal(screen.all((n) => n.tag === 'img').length, 2);

    // The next save carries the lock_version the picture answer brought, and nothing is flagged.
    assert.equal(screen.all((n) => n.props['data-test'] === 'conflict').length, 0);
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();
    assert.equal(calledWith(calls, 'updateProduct')[0].args[1].lock_version, 5);
    screen.unmount();
});

test('editor: a picture answer that jumped more than one version keeps the loaded version and says so at once', async () => {
    // Loaded at 4. A colleague saved (5), then this editor's upload made it 6: adopting 6 would let the
    // next Save pass the stale check and overwrite the colleague's price or sizes without a word.
    const { screen, calls } = await mountEditor({ productId: '5' }, shopStoreDouble({
        uploadImages: async () => product({ name: 'Renamed by a colleague', images: [picture(1)], lock_version: 6 }),
    }));

    await byId(screen, 'shop-picture-input').props.onChange({ target: { files: [{ name: 'a.jpg', size: 1000, type: 'image/jpeg' }], value: '' } });
    await flush();

    assert.equal(screen.all((n) => n.tag === 'img').length, 1, 'the pictures are the answer\'s all the same');
    const notice = screen.all((n) => n.props['data-test'] === 'conflict');
    assert.equal(notice.length, 1, 'the editor says so straight away, before any Save');
    assert.match(notice[0].textContent, /Changed elsewhere\./);
    assert.match(notice[0].textContent, /Your picture change is saved/);

    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();
    assert.equal(calledWith(calls, 'updateProduct')[0].args[1].lock_version, 4, 'Save sends the version this form loaded, so the server answers 409');
    screen.unmount();
});

test('editor: a server refusal of a picture is shown as it is, and the 10 MB default holds when the answer names none', async () => {
    const double: any = shopStoreDouble({
        fetchProduct: async () => { double.store.productsMeta = { currency: 'usd', max_images: 8 }; return { product: product(), meta: null }; },
        uploadImages: () => Promise.reject(httpError(422, { status: 'failed', data: { images: ['A product can have at most 8 pictures; this one has 8 and you sent 1.'] } })),
    });
    const { screen } = await mountEditor({ productId: '5' }, double);
    assert.match(screen.text(), /10 MB each/);

    await byId(screen, 'shop-picture-input').props.onChange({ target: { files: [{ name: 'a.jpg', size: 10, type: 'image/jpeg' }], value: '' } });
    await flush();
    assert.match(screen.all((n) => n.props['data-test'] === 'picture-problems')[0].textContent, /at most 8 pictures; this one has 8 and you sent 1/);
    screen.unmount();
});

test('editor: reordering and deleting a picture send the whole order and the id; each answer is the whole product', async () => {
    const swal = swalDouble();
    const { screen, calls } = await mountEditor({ productId: '5' }, shopStoreDouble({
        fetchProduct: async () => ({ product: product({ images: [picture(1), picture(2), picture(3)] }), meta }),
        reorderImages: async () => product({ images: [picture(2), picture(1), picture(3)], lock_version: 5 }),
        deleteImage: async () => product({ images: [picture(2), picture(1)], lock_version: 6 }),
    }), swal);

    click(screen.all((n) => n.tag === 'button' && n.props['aria-label'] === 'Move picture 1 later')[0]);
    await flush();
    assert.deepEqual(calledWith(calls, 'reorderImages')[0].args, [5, [2, 1, 3]]);

    click(screen.all((n) => n.tag === 'button' && n.props['aria-label'] === 'Delete picture 3')[0]);
    await flush();
    assert.deepEqual(calledWith(calls, 'deleteImage')[0].args, [5, 3]);
    assert.equal(screen.all((n) => n.tag === 'img').length, 2);
    screen.unmount();
});

test('editor: a product that is gone says so, and a 403 says the shop is off', async () => {
    const gone = await mountEditor({ productId: '99' }, shopStoreDouble({ fetchProduct: () => Promise.reject(httpError(404, { message: 'Not found.' })) }));
    assert.match(gone.screen.text(), /That product no longer exists/);
    gone.screen.unmount();

    const off = await mountEditor({ productId: '5' }, shopStoreDouble({
        fetchProduct: () => Promise.reject(httpError(403, { status: 'failed', message: 'Online shop is not switched on for this organisation.' })),
    }));
    assert.match(off.screen.text(), /Online shop is not switched on/);
    off.screen.unmount();
});

// --------------------------------------------------------------------------------------------- pickup

async function mountPickup(storeDouble = shopStoreDouble(), swal = swalDouble()) {
    const screen = await mountSfc('views/dashboard/shop/ShopPickupView.vue', {}, {
        sweetalert2: swal,
        '@/components/PageDataContainer.vue': { default: containerStub },
        './ShopTabs.vue': { default: tabsStub },
        '@/composables/useMinorUnits': minor,
        '@/core/helpers/shop': shop,
        '@/core/types/elements/Pagination': {},
        '@/stores/masjid/shopStore': storeDouble.module,
        '@/stores/masjidStore': masjidStoreModule,
    });
    await flush();

    return { screen, ...storeDouble, swal };
}

const salesDouble = (rows: any[], summary = summaryRows) => {
    const double: any = shopStoreDouble({ fetchSales: async () => { double.store.salesPaginated = pageOf(rows); double.store.salesMeta = { summary }; } });

    return double;
};

test('pickup: the header, the buyer, the item, the total and the paid time on the organisation\'s clock', async () => {
    const { screen } = await mountPickup(salesDouble([sale()]));

    const text = screen.text();
    assert.match(text, /Hoodie/);
    assert.match(text, /MEC-1/);
    assert.match(text, /Amira/);
    assert.match(text, /a@example\.org/);
    assert.match(text, /555-0101/);
    assert.match(text, /\$25\.00/);
    assert.match(text, /(7:04|19:04).*EDT/, 'the zone is the organisation\'s, not the browser\'s');
    // The header: units from the server's own summary, and its totals.
    assert.match(text, /All products 5 2 1/);
    assert.match(text, /1 line is oversold and needs a decision/);
    screen.unmount();
});

test('pickup: Hand out sends that sale, then the list is read again; a refunded or disputed line offers none', async () => {
    const double = salesDouble([
        sale({ id: 1, order_number: 'MEC-1' }),
        sale({ id: 2, order_number: 'MEC-2', refunded: true, charge_flag: 'refunded', to_hand_out: false }),
        sale({ id: 3, order_number: 'MEC-3', refunded: true, charge_flag: 'disputed', to_hand_out: false }),
        sale({ id: 4, order_number: 'MEC-4', charge_flag: 'partially_refunded' }),
    ]);
    const { screen, calls } = await mountPickup(double);

    const handOuts = screen.all((n) => n.tag === 'button' && n.props['aria-label']?.startsWith('Hand out order'));
    assert.deepEqual(handOuts.map((n) => n.props['aria-label']), ['Hand out order MEC-1', 'Hand out order MEC-4']);
    assert.match(screen.text(), /Refunded/);
    assert.match(screen.text(), /Disputed/);
    assert.match(screen.text(), /Partly refunded: check Stripe before handing out/);

    click(handOuts[0]);
    await flush();

    assert.deepEqual(calledWith(calls, 'collectSale').map((call) => call.args), [[1]]);
    assert.equal(calledWith(calls, 'fetchSales').length, 2, 'refreshed behind the table');
    screen.unmount();
});

test('pickup: a refused hand-out shows the server\'s sentence as it is', async () => {
    const refused: any = shopStoreDouble({
        fetchSales: async () => { refused.store.salesPaginated = pageOf([sale()]); refused.store.salesMeta = { summary: summaryRows }; },
        collectSale: () => Promise.reject(httpError(422, { status: 'failed', message: 'This order was refunded, so there is nothing to hand out.' })),
    });
    const { screen, swal } = await mountPickup(refused);

    click(screen.all((n) => n.tag === 'button' && n.props['aria-label'] === 'Hand out order MEC-1')[0]);
    await flush();

    assert.equal(swal.dialogs.at(-1).text, 'This order was refunded, so there is nothing to hand out.');
    assert.equal(swal.dialogs.at(-1).icon, 'error');
    screen.unmount();
});

test('pickup: Undo asks first and names who handed it out; declined, nothing is sent', async () => {
    const swal = swalDouble();
    swal.confirm = false;
    const double = salesDouble([sale({ collected_at: '2026-10-09T10:00:00Z', collected_by: { id: 2, name: 'Yusuf' }, to_hand_out: false })]);
    const { screen, calls } = await mountPickup(double, swal);

    click(screen.all((n) => n.tag === 'button' && n.props['aria-label'] === 'Undo hand out for order MEC-1')[0]);
    await flush();

    assert.match(swal.dialogs[0].text, /by Yusuf/);
    assert.equal(calledWith(calls, 'uncollectSale').length, 0);

    swal.confirm = true;
    click(screen.all((n) => n.tag === 'button' && n.props['aria-label'] === 'Undo hand out for order MEC-1')[0]);
    await flush();
    assert.deepEqual(calledWith(calls, 'uncollectSale').map((call) => call.args), [[1]]);
    screen.unmount();
});

test('pickup: an oversold line shows the red banner with both decisions; each sends its resolution; undo sends the delete', async () => {
    const swal = swalDouble();
    const double = salesDouble([
        sale({ id: 1, order_number: 'MEC-1', oversold: true }),
        sale({ id: 2, order_number: 'MEC-2', oversold: true, resolution: 'substituted', resolved_by: { id: 2, name: 'Yusuf' }, resolved_at: '2026-10-09T10:00:00Z' }),
        sale({ id: 3, order_number: 'MEC-3', oversold: true, resolution: 'refunded', to_hand_out: true }),
    ]);
    const { screen, calls } = await mountPickup(double, swal);

    const banners = screen.all((n) => n.props['data-test'] === 'oversold-banner');
    assert.equal(banners.length, 1, 'only the undecided line has the banner');
    assert.match(banners[0].textContent, /Oversold: refund it or substitute the item/);

    // A line resolved "substituted" is still to hand out; one resolved "refunded" is not.
    const handOuts = screen.all((n) => n.tag === 'button' && n.props['aria-label']?.startsWith('Hand out order'));
    assert.deepEqual(handOuts.map((n) => n.props['aria-label']), ['Hand out order MEC-1', 'Hand out order MEC-2']);

    click(screen.button('Mark substituted'));
    await flush();
    assert.deepEqual(calledWith(calls, 'resolveSale').map((call) => call.args), [[1, 'substituted']], 'substituting needs no confirm');

    click(screen.button('Mark refunded'));
    await flush();
    assert.ok(swal.dialogs.some((dialog) => /does not refund anything itself/.test(dialog.text ?? '')), 'a refund is confirmed first');
    assert.deepEqual(calledWith(calls, 'resolveSale').map((call) => call.args), [[1, 'substituted'], [1, 'refunded']]);

    click(screen.all((n) => n.tag === 'button' && n.props['aria-label'] === 'Undo the oversold decision for order MEC-2')[0]);
    await flush();
    assert.deepEqual(calledWith(calls, 'unresolveSale').map((call) => call.args), [[2]]);
    screen.unmount();
});

test('pickup: while one action is in flight every other button is off, and a second tap sends nothing', async () => {
    const answer = deferred();
    const double = salesDouble([sale({ id: 1, order_number: 'MEC-1' }), sale({ id: 2, order_number: 'MEC-2' })]);
    double.store.collectSale = (...args: any[]) => { double.calls.push({ name: 'collectSale', args }); return answer.promise; };
    const { screen, calls } = await mountPickup(double);

    const first = () => screen.all((n) => n.tag === 'button' && n.props['aria-label'] === 'Hand out order MEC-1')[0];
    const second = () => screen.all((n) => n.tag === 'button' && n.props['aria-label'] === 'Hand out order MEC-2')[0];

    assert.equal(click(first()), true);
    await flush();
    assert.equal(first().disabled, true);
    assert.equal(second().disabled, true);
    assert.equal(click(second()), false);
    assert.equal(calledWith(calls, 'collectSale').length, 1);

    answer.resolve(sale({ id: 1, collected_at: '2026-10-09T10:00:00Z', to_hand_out: false }));
    await flush();
    screen.unmount();
});

test('pickup: choosing a tab reads that state; Download CSV exports under the same filters', async () => {
    const { screen, calls } = await mountPickup(salesDouble([sale()]));

    click(screen.button('Collected'));
    await flush();
    assert.equal(calledWith(calls, 'fetchSales').at(-1)!.args[0].state, 'collected');
    assert.equal(calledWith(calls, 'fetchSales').at(-1)!.args[1], 1, 'back to the first page');

    type(byId(screen, 'shop-filter-search'), '  Amira ');
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();
    assert.equal(calledWith(calls, 'fetchSales').at(-1)!.args[0].search, 'Amira');

    click(screen.button('Download CSV'));
    await flush();
    const exported = calledWith(calls, 'exportSalesCsv');
    assert.equal(exported.length, 1);
    assert.deepEqual([exported[0].args[0].state, exported[0].args[0].search], ['collected', 'Amira']);
    screen.unmount();
});

test('pickup: an empty list says what it is empty of; a 403 says the shop is off', async () => {
    const empty = await mountPickup(salesDouble([], []));
    assert.match(empty.screen.text(), /Nothing is waiting to be handed out/);
    assert.match(empty.screen.text(), /Nothing has been sold yet/);
    empty.screen.unmount();

    const off = await mountPickup(shopStoreDouble({ fetchSales: () => Promise.reject(httpError(403, { status: 'failed', message: 'Online shop is not switched on for this organisation.' })) }));
    assert.match(off.screen.text(), /Online shop is not switched on/);
    off.screen.unmount();
});
