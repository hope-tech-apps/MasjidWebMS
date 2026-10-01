<template>
    <div>
        <PageDataContainer :title="isEdit ? 'Edit product' : 'New product'" :hideButton="true">
            <template #headerButtons>
                <router-link class="btn btn-outline-secondary" :to="{ name: 'masjid.shop.products' }">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
                    Products
                </router-link>
            </template>

            <div class="container w-100">
                <ShopTabs active="products" />

                <div v-if="loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                </div>

                <div v-else-if="notFound" class="text-center py-5 text-muted">
                    <i class="bi bi-question-circle fs-1 d-block mb-3" aria-hidden="true"></i>
                    <p class="mb-3">That product no longer exists.</p>
                    <router-link class="btn btn-outline-primary btn-sm" :to="{ name: 'masjid.shop.products' }">Back to the list</router-link>
                </div>

                <div v-else-if="loadError" class="alert" :class="forbidden ? 'alert-warning' : 'alert-danger'" role="alert">
                    {{ loadError }}
                    <button v-if="!forbidden" class="btn btn-sm btn-outline-danger ms-3" @click="load">Retry</button>
                </div>

                <template v-else>
                    <!-- Somebody else saved this product since it was opened here: nothing is retried. -->
                    <div v-if="conflict" class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="alert" data-test="conflict">
                        <span class="flex-grow-1">
                            <strong>{{ conflictLead }}</strong> {{ conflict }} Reloading shows their version and throws away what you changed here.
                        </span>
                        <button type="button" class="btn btn-sm btn-warning" :disabled="loading" @click="load">Reload</button>
                    </div>

                    <div v-if="formError" ref="errorSummary" class="alert alert-danger" role="alert" tabindex="-1" data-test="form-error">
                        {{ formError }}
                        <ul v-if="unplaced.length" class="mb-0 mt-1">
                            <li v-for="message in unplaced" :key="message">{{ message }}</li>
                        </ul>
                    </div>

                    <form ref="formRoot" novalidate @submit.prevent="save">
                        <!-- ============================================ THE PRODUCT -->
                        <h2 class="h5">Product</h2>
                        <div class="row g-3 mb-4">
                            <div class="col-md-8">
                                <label class="form-label" for="shop-name">Name</label>
                                <input id="shop-name" v-model="form.name" class="form-control" maxlength="120" dir="auto"
                                    :class="{ 'is-invalid': hasError('name') }" :aria-invalid="hasError('name') ? 'true' : undefined"
                                    aria-describedby="shop-name-error" autocomplete="off">
                                <div id="shop-name-error" class="invalid-feedback d-block" v-if="hasError('name')">{{ errorsOf('name').join(' ') }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="shop-category">Category <span class="text-muted">(optional)</span></label>
                                <input id="shop-category" v-model="form.category" class="form-control" maxlength="60" dir="auto"
                                    :class="{ 'is-invalid': hasError('category') }" :aria-invalid="hasError('category') ? 'true' : undefined"
                                    aria-describedby="shop-category-error" autocomplete="off">
                                <div id="shop-category-error" class="invalid-feedback d-block" v-if="hasError('category')">{{ errorsOf('category').join(' ') }}</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="shop-description">Description <span class="text-muted">(optional)</span></label>
                                <textarea id="shop-description" v-model="form.description" class="form-control" rows="3" maxlength="5000" dir="auto"
                                    :class="{ 'is-invalid': hasError('description') }" :aria-invalid="hasError('description') ? 'true' : undefined"
                                    aria-describedby="shop-description-error"></textarea>
                                <div id="shop-description-error" class="invalid-feedback d-block" v-if="hasError('description')">{{ errorsOf('description').join(' ') }}</div>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label class="form-label" for="shop-price">Price ({{ currencyCode }})</label>
                                <div class="input-group">
                                    <span class="input-group-text" aria-hidden="true">{{ symbol }}</span>
                                    <input id="shop-price" v-model="form.price" inputmode="decimal" class="form-control" placeholder="0.00"
                                        :class="{ 'is-invalid': hasError('base_price_minor') }" :aria-invalid="hasError('base_price_minor') ? 'true' : undefined"
                                        aria-describedby="shop-price-help shop-price-error" autocomplete="off">
                                </div>
                                <div id="shop-price-help" class="form-text">What a size costs unless it has its own price.</div>
                                <div id="shop-price-error" class="invalid-feedback d-block" v-if="hasError('base_price_minor')">{{ errorsOf('base_price_minor').join(' ') }}</div>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <label class="form-label" for="shop-sort">Order in the shop</label>
                                <input id="shop-sort" v-model="form.sort" inputmode="numeric" class="form-control"
                                    :class="{ 'is-invalid': hasError('sort') }" :aria-invalid="hasError('sort') ? 'true' : undefined"
                                    aria-describedby="shop-sort-help shop-sort-error" autocomplete="off">
                                <div id="shop-sort-help" class="form-text">Lower numbers are listed first.</div>
                                <div id="shop-sort-error" class="invalid-feedback d-block" v-if="hasError('sort')">{{ errorsOf('sort').join(' ') }}</div>
                            </div>
                            <div class="col-md-4 d-flex align-items-center">
                                <div class="form-check form-switch">
                                    <input id="shop-active" v-model="form.active" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label" for="shop-active">On sale</label>
                                </div>
                            </div>
                        </div>

                        <!-- ============================================ THE SIZES -->
                        <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2">
                            <h2 class="h5 mb-0">Sizes</h2>
                            <button type="button" class="btn btn-sm btn-outline-success" @click="addRow">
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add a size
                            </button>
                        </div>
                        <p class="text-muted small mt-1 mb-2">
                            Each size can have its own price and its own stock. Leave the price empty to use the product's, and the stock
                            empty for unlimited. Stock is the total for sale, including what has already sold. A size that has been
                            ordered keeps its name: to change it, switch the old one off and add a new size.
                        </p>
                        <div v-if="hasError('variants')" class="text-danger small mb-2" role="alert">{{ errorsOf('variants').join(' ') }}</div>

                        <p v-if="!form.variants.length" class="text-warning-emphasis small mb-3">
                            No sizes yet. A product with none cannot be bought: add at least one (for a single item, "One size").
                        </p>

                        <ol class="list-unstyled mb-4" aria-label="Sizes">
                            <li v-for="(row, index) in form.variants" :key="row.key" class="card card-body border-0 bg-light mb-2" data-test="size-row">
                                <div class="row g-2 align-items-start">
                                    <div class="col-sm-4 col-lg-3">
                                        <label class="form-label small text-muted mb-0" :for="`size-label-${row.key}`">Size name</label>
                                        <input :id="`size-label-${row.key}`" v-model="row.label" class="form-control form-control-sm" maxlength="40" dir="auto"
                                            :class="{ 'is-invalid': rowErrorsAt(index).label.length }"
                                            :aria-invalid="rowErrorsAt(index).label.length ? 'true' : undefined"
                                            :aria-describedby="`size-label-error-${row.key}`" autocomplete="off">
                                        <div :id="`size-label-error-${row.key}`" class="invalid-feedback d-block" v-if="rowErrorsAt(index).label.length">
                                            {{ rowErrorsAt(index).label.join(' ') }}
                                        </div>
                                    </div>
                                    <div class="col-6 col-sm-3 col-lg-2">
                                        <label class="form-label small text-muted mb-0" :for="`size-price-${row.key}`">Own price ({{ symbol }})</label>
                                        <input :id="`size-price-${row.key}`" v-model="row.price" inputmode="decimal" class="form-control form-control-sm"
                                            placeholder="Product price"
                                            :class="{ 'is-invalid': rowErrorsAt(index).price.length }"
                                            :aria-invalid="rowErrorsAt(index).price.length ? 'true' : undefined"
                                            :aria-describedby="`size-price-error-${row.key}`" autocomplete="off">
                                        <div :id="`size-price-error-${row.key}`" class="invalid-feedback d-block" v-if="rowErrorsAt(index).price.length">
                                            {{ rowErrorsAt(index).price.join(' ') }}
                                        </div>
                                    </div>
                                    <div class="col-6 col-sm-3 col-lg-2">
                                        <label class="form-label small text-muted mb-0" :for="`size-stock-${row.key}`">Stock</label>
                                        <input :id="`size-stock-${row.key}`" v-model="row.stock" inputmode="numeric" class="form-control form-control-sm"
                                            placeholder="Unlimited"
                                            :class="{ 'is-invalid': rowErrorsAt(index).stock.length }"
                                            :aria-invalid="rowErrorsAt(index).stock.length ? 'true' : undefined"
                                            :aria-describedby="`size-stock-help-${row.key} size-stock-error-${row.key}`" autocomplete="off">
                                        <div :id="`size-stock-help-${row.key}`" class="form-text mt-0">Total for sale, including sold</div>
                                        <div :id="`size-stock-error-${row.key}`" class="invalid-feedback d-block" v-if="rowErrorsAt(index).stock.length">
                                            {{ rowErrorsAt(index).stock.join(' ') }}
                                        </div>
                                    </div>
                                    <div class="col-6 col-sm-2 col-lg-1 pt-sm-4">
                                        <div class="form-check form-switch">
                                            <input :id="`size-on-${row.key}`" v-model="row.enabled" class="form-check-input" type="checkbox" role="switch">
                                            <label class="form-check-label small" :for="`size-on-${row.key}`">{{ row.enabled ? 'On' : 'Off' }}</label>
                                        </div>
                                    </div>
                                    <div class="col-6 col-sm-12 col-lg-4 text-end text-nowrap pt-lg-4">
                                        <div class="btn-group btn-group-sm me-1" role="group" :aria-label="`Move size ${sizeName(row)}`">
                                            <button type="button" class="btn btn-outline-secondary" :disabled="index === 0"
                                                :aria-label="`Move ${sizeName(row)} up`" @click="moveRow(index, index - 1)">↑</button>
                                            <button type="button" class="btn btn-outline-secondary" :disabled="index === form.variants.length - 1"
                                                :aria-label="`Move ${sizeName(row)} down`" @click="moveRow(index, index + 1)">↓</button>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger" :aria-label="`Remove ${sizeName(row)}`"
                                            @click="removeRow(index)">Remove</button>
                                    </div>
                                </div>
                                <p v-if="row.numbers" class="small text-muted mb-0 mt-1" data-test="stock-line">{{ stockLine(row.numbers) }}</p>
                                <p v-if="rowErrorsAt(index).other.length" class="text-danger small mb-0 mt-1">{{ rowErrorsAt(index).other.join(' ') }}</p>
                            </li>
                        </ol>

                        <div class="d-flex gap-2 mb-5">
                            <button type="submit" class="btn btn-success" :disabled="saving">
                                <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                                {{ isEdit ? 'Save changes' : 'Create product' }}
                            </button>
                            <router-link class="btn btn-link" :to="{ name: 'masjid.shop.products' }">Cancel</router-link>
                        </div>
                    </form>

                    <!-- ============================================ THE PICTURES: only once the product exists -->
                    <section v-if="product" aria-labelledby="shop-pictures-heading" data-test="pictures">
                        <h2 id="shop-pictures-heading" class="h5">Pictures</h2>
                        <p class="text-muted small mb-2">
                            {{ hint }} The first one is the one shown in lists. Pictures are saved as soon as you add, move or remove them;
                            the fields above need Save changes.
                        </p>

                        <div v-if="pictureProblems.length" class="alert alert-danger py-2 small" role="alert" data-test="picture-problems">
                            <ul class="mb-0 ps-3">
                                <li v-for="problem in pictureProblems" :key="problem">{{ problem }}</li>
                            </ul>
                        </div>

                        <p v-if="!product.images.length" class="text-muted">No pictures yet.</p>
                        <ul v-else class="list-unstyled d-flex flex-wrap gap-3 mb-3">
                            <li v-for="(image, index) in product.images" :key="image.id" class="shop-picture card border-0 bg-light">
                                <img :src="image.url" :alt="`${product.name}, picture ${index + 1} of ${product.images.length}`" class="shop-picture-img">
                                <div class="d-flex justify-content-between align-items-center p-1">
                                    <div class="btn-group btn-group-sm" role="group" :aria-label="`Move picture ${index + 1}`">
                                        <button type="button" class="btn btn-outline-secondary" :disabled="busyPictures || index === 0"
                                            :aria-label="`Move picture ${index + 1} earlier`" @click="movePicture(index, index - 1)">←</button>
                                        <button type="button" class="btn btn-outline-secondary" :disabled="busyPictures || index === product.images.length - 1"
                                            :aria-label="`Move picture ${index + 1} later`" @click="movePicture(index, index + 1)">→</button>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-danger" :disabled="busyPictures"
                                        :aria-label="`Delete picture ${index + 1}`" @click="deletePicture(image.id, index)">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </li>
                        </ul>

                        <div class="mb-5">
                            <label class="form-label" for="shop-picture-input">Add pictures</label>
                            <input id="shop-picture-input" ref="pictureInput" type="file" class="form-control" multiple
                                accept="image/jpeg,image/png,image/gif,image/webp" :disabled="busyPictures || pictureRoom <= 0"
                                aria-describedby="shop-picture-help" @change="onPicturesChosen">
                            <div id="shop-picture-help" class="form-text">
                                <span v-if="pictureRoom <= 0">This product has all {{ limits.maxImages }} pictures: remove one to add another.</span>
                                <span v-else>{{ hint }} Room for {{ pictureRoom }} more.</span>
                            </div>
                            <div v-if="busyPictures" class="small text-muted mt-1" role="status">
                                <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Working on the pictures...
                            </div>
                        </div>
                    </section>
                    <p v-else class="text-muted small mb-5">
                        Pictures can be added once the product has been created: create it first, and the picture section appears here.
                    </p>
                </template>
            </div>
        </PageDataContainer>
    </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeMount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Swal from 'sweetalert2';
import PageDataContainer from '@/components/PageDataContainer.vue';
import ShopTabs from './ShopTabs.vue';
import { currencyExponent, parseMajorToMinor } from '@/composables/useMinorUnits';
import {
    blankProductForm, blankVariantRow, buildProductRequest, checkImageFiles, classifyFailure, currencySymbol, errorsFor, formFromProduct,
    imageHint, imageLimits, moveItem, imageOrderBody, pictureAnswerVersion, removalWarning, removedVariants, rowErrors, stockLine, unplacedErrors
} from '@/core/helpers/shop';
import type { EditorForm, EditorRow, FieldErrors, PriceCodec, RowErrors } from '@/core/helpers/shop';
import type { ShopProduct } from '@/core/types/data/masjid-related/Shop';
import { shopErrorReader, useShopStore } from '@/stores/masjid/shopStore';

/**
 * Create and edit one product, with its sizes and its pictures.
 *
 *   MONEY. The price is typed in dollars and converted to integer cents by `parseMajorToMinor` (the only
 *   place that does it), and sent as a JSON NUMBER. The currency is the shop's (`meta.currency`): never
 *   typed here, never sent. Nor is `slug`, which the server makes at creation and never changes.
 *
 *   SIZES are saved as the WHOLE list: a row with an `id` edits that size, a row without one adds it, and
 *   a size taken out of the list is removed (a confirm names it first). Each row's place is sent as its
 *   `sort`. The read-only line under a saved size is the server's own numbers.
 *
 *   `lock_version`. Every product answer carries one and every save sends back the one this editor
 *   loaded. A 409 means somebody else changed the product: the message is shown with a Reload that
 *   refetches and throws this form away. Nothing is ever retried.
 *
 *   A 422 comes with flat dotted keys (`name`, `variants.0.label`); each is shown beside its field or
 *   row, and any that match no field are listed at the top so none is lost.
 *
 *   PICTURES need the product to exist, so the section appears once it has been created. Every picture
 *   answer is the whole product: it replaces `product` (its new `lock_version` too) and leaves the
 *   unsaved fields alone.
 */

const route = useRoute();
const router = useRouter();
const shopStore = useShopStore();

const productId = computed<number | null>(() => (route.params.productId ? Number(route.params.productId) : null));
const isEdit = computed<boolean>(() => productId.value !== null);

const loading = ref(true);
const loadError = ref('');
const forbidden = ref(false);
const notFound = ref(false);

/** The product as the server last gave it (its `lock_version`, its pictures); null before it exists. */
const product = ref<ShopProduct | null>(null);
const form = ref<EditorForm>(blankProductForm());
const fieldErrors = ref<FieldErrors>({});
const formError = ref('');
const conflict = ref('');
/** How the conflict notice opens: a refused Save, or a change noticed from a picture answer. */
const conflictLead = ref('Not saved.');
const saving = ref(false);

const busyPictures = ref(false);
const pictureProblems = ref<string[]>([]);

const formRoot = ref<HTMLFormElement | null>(null);
const errorSummary = ref<HTMLElement | null>(null);
const pictureInput = ref<HTMLInputElement | null>(null);

// ---------------------------------------------------------------------------------- money and limits

const currency = computed<string>(() => shopStore.productsMeta?.currency ?? 'usd');
const currencyCode = computed<string>(() => currency.value.toUpperCase());
const symbol = computed<string>(() => currencySymbol(currency.value));

const codec = computed<PriceCodec>(() => ({
    exponent: currencyExponent(currency.value),
    parse: (text: string) => parseMajorToMinor(text, currency.value)
}));

const limits = computed(() => imageLimits(shopStore.productsMeta));
const hint = computed(() => imageHint(limits.value));
const pictureRoom = computed<number>(() => limits.value.maxImages - (product.value?.images.length ?? 0));

// ------------------------------------------------------------------------------------------- errors

const hasError = (key: string): boolean => errorsFor(fieldErrors.value, key).length > 0;
const errorsOf = (key: string): string[] => errorsFor(fieldErrors.value, key);
const rowErrorsAt = (index: number): RowErrors => rowErrors(fieldErrors.value, index);
const unplaced = computed<string[]>(() => unplacedErrors(fieldErrors.value));

const sizeName = (row: EditorRow): string => row.label.trim() || 'this size';

const toast = (icon: 'success' | 'error', text: string) => {
    Swal.fire({ icon, text, timer: 2500, showConfirmButton: false, toast: true, position: 'top-end' });
};

/** After an invalid save the first marked field takes focus, so a keyboard user lands on the problem. */
async function focusFirstProblem() {
    await nextTick();

    const first = formRoot.value?.querySelector<HTMLElement>('[aria-invalid="true"]');
    if (first) first.focus();
    else errorSummary.value?.focus();
}

// ----------------------------------------------------------------------------------------- loading

function adopt(loaded: ShopProduct) {
    product.value = loaded;
    form.value = formFromProduct(loaded, codec.value.exponent);
    fieldErrors.value = {};
}

async function load() {
    loading.value = true;
    loadError.value = '';
    forbidden.value = false;
    notFound.value = false;
    conflict.value = '';
    formError.value = '';
    fieldErrors.value = {};
    pictureProblems.value = [];

    try {
        if (productId.value !== null) {
            const { product: loaded } = await shopStore.fetchProduct(productId.value);
            adopt(loaded);
        } else {
            // A new product has no answer to read the shop's currency from yet: the list carries it.
            if (!shopStore.productsMeta) await shopStore.fetchProducts(1);
            product.value = null;
            form.value = blankProductForm();
        }
    } catch (error) {
        const failure = classifyFailure(error, 'The product could not be loaded.', shopErrorReader);

        if (failure.kind === 'not_found') notFound.value = true;
        else {
            forbidden.value = failure.kind === 'forbidden';
            loadError.value = failure.message;
        }
    } finally {
        loading.value = false;
    }
}

// ------------------------------------------------------------------------------------------- sizes

function addRow() {
    const row = blankVariantRow();
    form.value.variants.push(row);

    nextTick(() => document.getElementById(`size-label-${row.key}`)?.focus());
}

function moveRow(from: number, to: number) {
    form.value.variants = moveItem(form.value.variants, from, to);
    // The row's error keys name its place in the list: they no longer line up once it moved.
    fieldErrors.value = {};
}

function removeRow(index: number) {
    form.value.variants.splice(index, 1);
    fieldErrors.value = {};
}

// -------------------------------------------------------------------------------------------- save

async function save() {
    if (saving.value) return;

    formError.value = '';
    conflict.value = '';
    conflictLead.value = 'Not saved.';

    const built = buildProductRequest(form.value, codec.value, product.value?.lock_version ?? null);
    if (!built.ok) {
        fieldErrors.value = built.errors;
        formError.value = 'The product was not saved. Fix the fields marked below.';
        await focusFirstProblem();
        return;
    }

    // A size left out of the list is removed by this save: say which, before it is.
    const removed = removedVariants(product.value?.variants, form.value);
    if (removed.length) {
        const confirmed = await Swal.fire({
            title: removed.length === 1 ? 'Remove this size?' : 'Remove these sizes?',
            text: removalWarning(removed),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Save and remove',
            cancelButtonText: 'Go back'
        });
        if (!confirmed.isConfirmed) return;
    }

    saving.value = true;
    fieldErrors.value = {};

    try {
        if (productId.value === null) {
            const { product: created } = await shopStore.createProduct(built.body);
            adopt(created);
            toast('success', 'Product created. You can add pictures now.');
            // The editor stays where it is and the address catches up: the next save is an edit.
            await router.replace({ name: 'masjid.shop.productEdit', params: { productId: created.id } });
        } else {
            const { product: saved } = await shopStore.updateProduct(productId.value, built.body);
            adopt(saved);
            toast('success', 'Saved.');
        }
    } catch (error) {
        const failure = classifyFailure(error, 'The product was not saved.', shopErrorReader);

        if (failure.kind === 'conflict') {
            conflict.value = failure.message;
        } else if (failure.kind === 'invalid') {
            fieldErrors.value = failure.fields;
            formError.value = 'The product was not saved. Fix the fields marked below.';
            await focusFirstProblem();
        } else {
            formError.value = failure.message;
            await nextTick();
            errorSummary.value?.focus();
        }
    } finally {
        saving.value = false;
    }
}

// ---------------------------------------------------------------------------------------- pictures

/**
 * Every picture answer is the whole product. Its PICTURES always replace ours and the unsaved fields
 * stay. Its `lock_version` is adopted only when it is ours plus one, which is this editor's own
 * picture change; a bigger jump is a colleague's save in between, so the version this form loaded
 * is kept (its next Save is refused with a 409, never a silent overwrite) and the notice is shown
 * straight away (pictureAnswerVersion).
 */
function adoptAnswer(answer: ShopProduct) {
    const held = product.value;

    if (!held) {
        product.value = answer;
        return;
    }

    const verdict = pictureAnswerVersion(held.lock_version, answer.lock_version);

    if (!verdict.changedElsewhere) {
        product.value = answer;
        return;
    }

    product.value = { ...held, images: answer.images };
    conflictLead.value = 'Changed elsewhere.';
    conflict.value = 'Your picture change is saved, but someone else changed this product while you had it open.';
}

function pictureFailure(error: unknown, fallback: string): string {
    if ((error as any)?.response?.status === 413) return `A picture can be at most ${limits.value.maxMb} MB.`;

    return classifyFailure(error, fallback, shopErrorReader).message;
}

async function onPicturesChosen(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    if (!files.length || !product.value || busyPictures.value) return;

    pictureProblems.value = checkImageFiles(files, limits.value, product.value.images.length);
    if (pictureProblems.value.length) {
        input.value = '';
        return;
    }

    busyPictures.value = true;
    try {
        adoptAnswer(await shopStore.uploadImages(product.value.id, files));
        toast('success', files.length === 1 ? 'Picture added.' : `${files.length} pictures added.`);
    } catch (error) {
        // A 422 still carries the server's sentence (a file it will not take, the picture limit).
        pictureProblems.value = [pictureFailure(error, 'The pictures were not uploaded.')];
    } finally {
        busyPictures.value = false;
        if (pictureInput.value) pictureInput.value.value = '';
    }
}

async function movePicture(from: number, to: number) {
    if (!product.value || busyPictures.value) return;

    busyPictures.value = true;
    pictureProblems.value = [];
    try {
        const order = imageOrderBody(moveItem(product.value.images, from, to)).order;
        adoptAnswer(await shopStore.reorderImages(product.value.id, order));
    } catch (error) {
        pictureProblems.value = [pictureFailure(error, 'The pictures were not reordered.')];
    } finally {
        busyPictures.value = false;
    }
}

async function deletePicture(mediaId: number, index: number) {
    if (!product.value || busyPictures.value) return;

    const confirmed = await Swal.fire({
        title: `Delete picture ${index + 1}?`,
        text: 'It is removed from the product at once.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete picture',
        cancelButtonText: 'Keep it'
    });
    if (!confirmed.isConfirmed) return;

    busyPictures.value = true;
    pictureProblems.value = [];
    try {
        adoptAnswer(await shopStore.deleteImage(product.value.id, mediaId));
    } catch (error) {
        pictureProblems.value = [pictureFailure(error, 'The picture was not deleted.')];
    } finally {
        busyPictures.value = false;
    }
}

// ---------------------------------------------------------------------------------------- lifecycle

onBeforeMount(load);

// The same screen serves "new" and "edit": a different address means a different product. The create
// above moves the address to the product it just made, which this screen already holds, so it is left alone.
watch(productId, (id) => {
    if (id === (product.value?.id ?? null)) return;
    load();
});
</script>

<style scoped>
.shop-picture {
    width: 160px;
}

.shop-picture-img {
    display: block;
    width: 100%;
    height: 120px;
    object-fit: cover;
    border-radius: .5rem .5rem 0 0;
    background: #f1f3f5;
}
</style>
