<template>
    <div>
        <PageDataContainer title="Shop" :buttonProps="newButton" :paginationOptions="paginationOptions"
            @headerButtonClick="newProduct" @pageChange="pageChange">
            <div class="container w-100">
                <ShopTabs active="products" />

                <p class="text-muted small mb-3">
                    What {{ masjidStore.masjid?.name || 'this organisation' }} sells. A product is sold in sizes, each with its own
                    price and stock if you want. Switch a product off to stop selling it without losing it; deleting one never
                    touches the orders already paid.
                </p>

                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                </div>

                <div v-else-if="loadError" class="alert" :class="forbidden ? 'alert-warning' : 'alert-danger'" role="alert">
                    {{ loadError }}
                    <button v-if="!forbidden" class="btn btn-sm btn-outline-danger ms-3" @click="load(page)">Retry</button>
                </div>

                <div v-else-if="!products.length" class="text-center py-5 text-muted">
                    <i class="bi bi-bag fs-1 d-block mb-3" aria-hidden="true"></i>
                    <p class="mb-3">No products yet.</p>
                    <button class="btn btn-success btn-sm" @click="newProduct">Add the first product</button>
                </div>

                <div v-else class="table-responsive">
                    <table class="table align-middle">
                        <caption class="visually-hidden">Products in the shop</caption>
                        <thead>
                            <tr>
                                <th scope="col"><span class="visually-hidden">Picture</span></th>
                                <th scope="col">Name</th>
                                <th scope="col">Category</th>
                                <th scope="col">Price</th>
                                <th scope="col">Sizes</th>
                                <th scope="col">Active</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="product in products" :key="product.id" :class="{ 'text-muted': !product.active }">
                                <td class="shop-thumb-cell">
                                    <img v-if="product.images?.length" :src="product.images[0].url" alt="" class="shop-thumb" loading="lazy">
                                    <span v-else class="shop-thumb shop-thumb--empty" aria-hidden="true"><i class="bi bi-image"></i></span>
                                </td>
                                <td dir="auto">
                                    <router-link :to="editTo(product.id)" class="fw-semibold text-decoration-none">{{ product.name }}</router-link>
                                    <span v-if="!product.active" class="badge bg-secondary-subtle text-secondary ms-1">Off</span>
                                </td>
                                <td dir="auto">{{ product.category || '—' }}</td>
                                <td class="text-nowrap">{{ price(product) }}</td>
                                <td>
                                    <span v-if="!product.variants.length" class="small text-warning-emphasis">
                                        No sizes: nobody can buy this yet
                                    </span>
                                    <ul v-else class="list-inline mb-0">
                                        <li v-for="chip in chipsOf(product)" :key="chip.key" class="list-inline-item me-1 mb-1">
                                            <span class="badge" :class="chipClass(chip.state)" dir="auto">
                                                {{ chip.label }}<template v-if="chip.note"> · {{ chip.note }}</template>
                                            </span>
                                        </li>
                                    </ul>
                                </td>
                                <td>
                                    <div class="form-check form-switch mb-0">
                                        <input
                                            :id="`shop-active-${product.id}`"
                                            class="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                            :checked="product.active"
                                            :disabled="busyId !== null"
                                            :aria-label="`${product.name} is on sale`"
                                            @change="toggleActive(product, ($event.target as HTMLInputElement).checked)"
                                        >
                                    </div>
                                </td>
                                <td class="text-end text-nowrap">
                                    <router-link :to="editTo(product.id)" class="btn btn-sm btn-outline-secondary me-1"
                                        :aria-label="`Edit ${product.name}`">Edit</router-link>
                                    <button type="button" class="btn btn-sm btn-outline-danger" :disabled="busyId !== null"
                                        :aria-label="`Delete ${product.name}`" @click="remove(product)">Delete</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeMount, ref } from 'vue';
import { useRouter } from 'vue-router';
import Swal from 'sweetalert2';
import PageDataContainer from '@/components/PageDataContainer.vue';
import ShopTabs from './ShopTabs.vue';
import { formatMinor } from '@/composables/useMinorUnits';
import { classifyFailure, priceLabel, sizeChips } from '@/core/helpers/shop';
import type { SizeChip } from '@/core/helpers/shop';
import { ButtonProps } from '@/core/types/elements/Buttons';
import { PageChangeData, PaginationOptions } from '@/core/types/elements/Pagination';
import type { ShopProduct } from '@/core/types/data/masjid-related/Shop';
import { shopErrorReader, useShopStore } from '@/stores/masjid/shopStore';
import { useMasjidStore } from '@/stores/masjidStore';

/**
 * The shop's products: a page of them with a picture, the price ("from $X" when the sizes differ), each
 * size as a chip (off or sold out when it is), and an on/off switch.
 *
 * Every figure here is the server's: the price a size charges, whether it is sold out (`available` 0),
 * the currency (`meta.currency`). The on/off switch saves only `active` with the `lock_version` the row
 * was loaded with; a 409 means somebody else changed the product, and the list is read again rather
 * than the save retried. A delete is a soft delete: paid orders keep their own record.
 */

const router = useRouter();
const masjidStore = useMasjidStore();
const shopStore = useShopStore();

const loading = ref(true);
const loadError = ref('');
const forbidden = ref(false);
const page = ref(1);
const busyId = ref<number | null>(null);

const newButton: ButtonProps = { title: 'New product', type: 'button', class: 'btn btn-success', disabled: false };

const paginationOptions = ref<PaginationOptions>({ itemsTotal: 0, currentPage: 0, perPage: 25 });

const products = computed<ShopProduct[]>(() => (shopStore.productsPaginated?.data ?? []) as ShopProduct[]);

/** The currency is the shop's (`meta.currency`), never a product's own claim. */
const currency = computed<string>(() => shopStore.productsMeta?.currency ?? 'usd');

const price = (product: ShopProduct): string => priceLabel(product, (minor) => formatMinor(minor, currency.value));
const chipsOf = (product: ShopProduct): SizeChip[] => sizeChips(product);

const chipClass = (state: SizeChip['state']): string => {
    if (state === 'off') return 'bg-secondary-subtle text-secondary';
    if (state === 'sold_out') return 'bg-danger-subtle text-danger-emphasis';

    return 'bg-light text-dark border';
};

const editTo = (id: number) => ({ name: 'masjid.shop.productEdit', params: { productId: id } });

const toast = (icon: 'success' | 'error', text: string) => {
    Swal.fire({ icon, text, timer: 2500, showConfirmButton: false, toast: true, position: 'top-end' });
};

const syncPagination = () => {
    paginationOptions.value.itemsTotal = shopStore.productsPaginated?.total ?? 0;
    paginationOptions.value.currentPage = shopStore.productsPaginated?.current_page ?? 0;
    paginationOptions.value.perPage = shopStore.productsPaginated?.per_page ?? 25;
};

async function load(toPage: number = 1) {
    loading.value = true;
    loadError.value = '';
    forbidden.value = false;

    try {
        await shopStore.fetchProducts(toPage);
        page.value = toPage;
        syncPagination();
    } catch (error) {
        const failure = classifyFailure(error, 'The shop could not be loaded.', shopErrorReader);
        // The shop is off for this organisation, or this person may not read it: said as the server says it.
        forbidden.value = failure.kind === 'forbidden';
        loadError.value = failure.message;
    } finally {
        loading.value = false;
    }
}

const pageChange = (data: PageChangeData) => load(data.toPage);

const newProduct = () => {
    router.push({ name: 'masjid.shop.productNew' });
};

/** Put the answer for one product into the page, keeping its place. */
function applyProduct(updated: ShopProduct) {
    const rows = shopStore.productsPaginated?.data;
    if (!rows) return;

    const index = rows.findIndex((row: ShopProduct) => row.id === updated.id);
    if (index >= 0) rows[index] = updated;
}

async function toggleActive(product: ShopProduct, active: boolean) {
    if (busyId.value !== null) return;
    busyId.value = product.id;

    try {
        const { product: saved } = await shopStore.updateProduct(product.id, { active, lock_version: product.lock_version });
        applyProduct(saved);
        toast('success', active ? `${saved.name} is on sale.` : `${saved.name} is off sale.`);
    } catch (error) {
        const failure = classifyFailure(error, 'The product was not changed.', shopErrorReader);
        await Swal.fire({
            icon: failure.kind === 'conflict' ? 'warning' : 'error',
            title: failure.kind === 'conflict' ? 'Somebody else changed this product' : 'Not saved',
            text: failure.message
        });
        // The switch shows what the server has, not what was clicked: read the page again.
        await load(page.value);
    } finally {
        busyId.value = null;
    }
}

async function remove(product: ShopProduct) {
    if (busyId.value !== null) return;

    const confirmed = await Swal.fire({
        title: `Delete ${product.name}?`,
        text: 'It stops being sold at once. Paid orders keep their own record, so the pickup list and the receipts still read as they did.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete product',
        cancelButtonText: 'Keep it'
    });

    if (!confirmed.isConfirmed) return;

    busyId.value = product.id;
    try {
        await shopStore.deleteProduct(product.id);
        toast('success', `${product.name} was deleted.`);
        // Last row of a later page: step back one, or the page would be empty.
        await load(products.value.length === 1 && page.value > 1 ? page.value - 1 : page.value);
    } catch (error) {
        const failure = classifyFailure(error, 'The product was not deleted.', shopErrorReader);
        Swal.fire({ icon: 'error', title: 'Not deleted', text: failure.message });
    } finally {
        busyId.value = null;
    }
}

onBeforeMount(() => load(1));
</script>

<style scoped>
.shop-thumb-cell {
    width: 64px;
}

.shop-thumb {
    display: block;
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: .5rem;
    background: #f1f3f5;
}

.shop-thumb--empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #adb5bd;
}
</style>
