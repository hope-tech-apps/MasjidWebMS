<template>
    <div class="shop-editor">
        <div class="alert alert-info py-2 px-3 small">
            <i class="bi bi-info-circle me-2"></i>
            Products, prices and sizes come from Shop → Products.
        </div>
        <div class="row">
            <div class="col-12 mb-3">
                <label class="form-label">Heading</label>
                <input
                    type="text"
                    class="form-control"
                    v-model="localContent.heading"
                    @input="emitUpdate"
                    :maxlength="SHOP_HEADING_MAX"
                    placeholder="e.g., Our uniform shop"
                />
                <div class="form-text">Optional.</div>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Category</label>
                <!--
                    The organisation's own categories, read from its products. If that list cannot be
                    read (the shop is not switched on for the admin, or the call fails) the admin types
                    the category instead: the renderer compares it to each product's, as written.
                -->
                <input
                    v-if="categoriesState === 'unavailable'"
                    type="text"
                    class="form-control"
                    v-model="localContent.category"
                    @input="emitUpdate"
                    :maxlength="SHOP_CATEGORY_MAX"
                />
                <select
                    v-else
                    class="form-select"
                    v-model="localContent.category"
                    @change="emitUpdate"
                    :disabled="categoriesState === 'loading'"
                >
                    <option value="">All categories</option>
                    <option v-for="category in categoryOptions" :key="category" :value="category">
                        {{ category }}
                    </option>
                </select>
                <div v-if="categoriesState === 'unavailable'" class="form-text">
                    Leave empty for all categories
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">How many to show</label>
                <input
                    type="number"
                    class="form-control"
                    v-model.number="localContent.max_items"
                    @input="emitUpdate"
                    @change="onMaxItemsChange"
                    :min="SHOP_MIN_ITEMS"
                    :max="SHOP_MAX_ITEMS"
                    step="1"
                />
                <div class="form-text">From {{ SHOP_MIN_ITEMS }} to {{ SHOP_MAX_ITEMS }}.</div>
            </div>
            <div class="col-12 mb-3">
                <div class="form-check form-switch">
                    <input
                        id="shop-show-view-all"
                        type="checkbox"
                        class="form-check-input"
                        role="switch"
                        v-model="localContent.show_view_all"
                        @change="emitUpdate"
                    />
                    <label class="form-check-label" for="shop-show-view-all">
                        Show a link to the full shop
                    </label>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ShopSectionContent } from '@/core/types/data/masjid-related/PageSection';
import {
    SHOP_CATEGORY_MAX,
    SHOP_HEADING_MAX,
    SHOP_MAX_ITEMS,
    SHOP_MIN_ITEMS,
    clampShopMaxItems,
    shopCategoriesFrom,
} from '@/core/helpers/shopSection';
import ApiService from '@/core/services/ApiService';
import { useMasjidStore } from '@/stores/masjidStore';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    modelValue: ShopSectionContent;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: ShopSectionContent];
}>();

// The form's own copy. `heading` and `category` are strings here (an empty input is ''), and
// become null again on the way out, which is how the section stores "none".
type LocalShopContent = {
    heading: string;
    category: string;
    max_items: number;
    show_view_all: boolean;
};

const normalize = (content?: Partial<ShopSectionContent> | null): LocalShopContent => ({
    heading: typeof content?.heading === 'string' ? content.heading : '',
    category: typeof content?.category === 'string' ? content.category : '',
    max_items: clampShopMaxItems(content?.max_items),
    // On unless the section says otherwise: content saved before the field existed shows the link.
    show_view_all: typeof content?.show_view_all === 'boolean' ? content.show_view_all : true,
});

const localContent = ref<LocalShopContent>(normalize(props.modelValue));

// What this editor last sent up. The parent hands it straight back as `modelValue`, and
// re-reading our own output would put 8 into a number field the admin has just cleared to
// type 12.
let lastEmitted = '';

watch(() => props.modelValue, (newVal) => {
    if (newVal && JSON.stringify(newVal) !== lastEmitted) {
        localContent.value = normalize(newVal);
    }
}, { deep: true });

const emitUpdate = () => {
    const heading = localContent.value.heading ?? '';
    const category = localContent.value.category ?? '';
    const out: ShopSectionContent = {
        heading: heading.trim() === '' ? null : heading,
        category: category.trim() === '' ? null : category,
        max_items: clampShopMaxItems(localContent.value.max_items),
        show_view_all: localContent.value.show_view_all,
    };

    lastEmitted = JSON.stringify(out);
    emit('update:modelValue', out);
};

// While typing, 3 on the way to 30 must not be forced to anything. When the field is left, show the
// number that was actually saved.
const onMaxItemsChange = () => {
    localContent.value.max_items = clampShopMaxItems(localContent.value.max_items);
    emitUpdate();
};

// The organisation's distinct product categories. 'unavailable' is the plain-text fallback.
const categoriesState = ref<'loading' | 'ready' | 'unavailable'>('loading');
const categories = ref<string[]>([]);

// A category already on the section stays selectable even when no product carries it any more, so
// opening the editor never silently turns it into "All categories".
const categoryOptions = computed(() => {
    const current = localContent.value.category;

    return current !== '' && !categories.value.includes(current)
        ? [current, ...categories.value]
        : categories.value;
});

onMounted(async () => {
    const masjidId = useMasjidStore().masjid?.id;

    if (!masjidId) {
        categoriesState.value = 'unavailable';
        return;
    }

    try {
        const res = await ApiService.get(`/api/admin/masjids/${masjidId}/shop/products?per_page=100`);
        const found = shopCategoriesFrom(res.data);

        if (found === null) {
            categoriesState.value = 'unavailable';
            return;
        }

        categories.value = found;
        categoriesState.value = 'ready';
    } catch {
        // 403 (no shop screen for this admin) or 404 (the products list is not there): type it.
        categoriesState.value = 'unavailable';
    }
});
</script>
