<template>
    <!--
        The sign-in screens (sign-in, forgot and set password) draw their own
        full-bleed frame (components/auth/AuthShell.vue) and ask for it with
        `meta.fullBleed`; the patterned background here would only be painted
        underneath it and downloaded for nothing. Every other page in this layout
        (the SuperAdmin dashboards, 401, 404) keeps it exactly as before.
    -->
    <div id="auth_layout" class="auth-layout" :class="{ 'auth-layout--bleed': fullBleed }">
        <div :class="fullBleed ? 'auth-layout__bleed' : 'background'">
            <RouterView></RouterView>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';

const route = useRoute();
const fullBleed = computed(() => route.meta.fullBleed === true);
</script>

<style scoped>
#auth_layout {
    width: 100%;
    background-image: url('../public/media/images/zokhrufa.png');
    background-size: cover;
}

#auth_layout.auth-layout--bleed {
    background-image: none;
}

#auth_layout .background {
    width: 100%;
    background-image: url('../public/media/images/masjid.png');
    background-size: 45%;
    background-position: bottom center;
    background-repeat: no-repeat;
}

#auth_layout .auth-layout__bleed {
    width: 100%;
}

@media(max-width: 991px) {
    #auth_layout .background {
        background-size: 75%;
    }
}

@media(max-width: 480px) {
    #auth_layout .background {
        background-size: 100%;
        padding-left: 2rem;
        padding-right: 2rem;
    }
}

</style>
