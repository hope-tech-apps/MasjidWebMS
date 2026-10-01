<template>
    <ul class="nav nav-tabs mb-4" aria-label="Shop sections">
        <li class="nav-item">
            <router-link
                class="nav-link"
                :class="{ active: active === 'products' }"
                :aria-current="active === 'products' ? 'page' : undefined"
                :to="{ name: 'masjid.shop.products' }"
            >
                <i class="bi bi-box-seam me-1" aria-hidden="true"></i>
                Products
            </router-link>
        </li>
        <li class="nav-item">
            <router-link
                class="nav-link"
                :class="{ active: active === 'pickup' }"
                :aria-current="active === 'pickup' ? 'page' : undefined"
                :to="{ name: 'masjid.shop.pickup' }"
            >
                <i class="bi bi-bag-check me-1" aria-hidden="true"></i>
                Pickup list
            </router-link>
        </li>
    </ul>
</template>

<script setup lang="ts">
import type { PropType } from 'vue';

/** The shop's two screens as tabs (the sidebar has one "Shop" entry and no sub-items). */
defineProps({
    active: {
        type: String as PropType<'products' | 'pickup'>,
        required: true
    }
});
</script>
