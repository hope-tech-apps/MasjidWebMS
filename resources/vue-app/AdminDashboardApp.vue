<template>
    <!--
        Mounted here, above the RouterView, so EVERY screen gets it: admin,
        teacher, family, lunch and the public org portal all render through this
        one root component. Putting it in a layout instead would silently skip
        whichever realm does not use that layout. See EnvironmentRibbon.vue —
        it renders nothing at all in production.
    -->
    <EnvironmentRibbon />
    <RouterView></RouterView>
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router';
import EnvironmentRibbon from '@/components/common/EnvironmentRibbon.vue';
</script>

<style scoped>
/* Your styles here */
</style>
