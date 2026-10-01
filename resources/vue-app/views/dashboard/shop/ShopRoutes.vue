<template>
    <RouterView />
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router';

/**
 * The shop's route parent (router/routes/shopManagementRoutes.ts): it draws nothing of its own. It
 * exists so the sidebar's "Shop" entry stays lit on every shop screen, which a flat list of siblings
 * would not do.
 */
</script>
