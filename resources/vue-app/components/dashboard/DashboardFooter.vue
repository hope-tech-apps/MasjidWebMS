<template>
    <footer id="dashboard_footer">
        <div class="fs-7">
            Copyrights ©
            <span v-if="currentYear > productionYear"> {{ productionYear }} - </span>
            {{ currentYear }},
            All Rights Reserved
        </div>
    </footer>
</template>

<script setup lang="ts">
import { ref } from 'vue';

// Custom constants
const productionYear = ref<number>(2025)
const currentYear = ref<number>((new Date()).getFullYear())

</script>
