<template>
    <div id="auth_layout" class="auth-layout">
        <div class="background">
            <RouterView></RouterView>
        </div>
    </div>
</template>

<script setup lang="ts">
import { RouterView } from 'vue-router';
</script>

<style scoped>
#auth_layout {
    width: 100%;
    background-image: url('../public/media/images/zokhrufa.png');
    background-size: cover;
}

#auth_layout .background {
    width: 100%;
    background-image: url('../public/media/images/masjid.png');
    background-size: 45%;
    background-position: bottom center;
    background-repeat: no-repeat;
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