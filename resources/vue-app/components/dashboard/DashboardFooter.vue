<template>
    <footer id="dashboard_footer">
        <div class="mn-footer">
            <span>
                &copy;
                <span v-if="currentYear > productionYear">{{ productionYear }}&ndash;</span>{{ currentYear }}
                Hope Tech. All rights reserved.
            </span>
            <span class="mn-footer-brand">
                <img :src="'/manara-icon.svg'" alt="" width="16" height="16">
                Manara
            </span>
        </div>
    </footer>
</template>

<script setup lang="ts">
import { ref } from 'vue';

// Custom constants
const productionYear = ref<number>(2025)
const currentYear = ref<number>((new Date()).getFullYear())

</script>

<style scoped>
.mn-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem 1rem;
    border-top: 1px solid #e3e8ee;
    padding-top: 0.85rem;
}

.mn-footer-brand {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: #5d6275;
    font-weight: 650;
}

.mn-footer-brand img {
    border-radius: 4px;
}
</style>
