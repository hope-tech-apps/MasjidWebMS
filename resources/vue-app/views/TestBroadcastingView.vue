<template>
    <div>Test</div>
</template>

<script setup>
import { onMounted } from 'vue';
import '../echo'

onMounted(() => {
    window.Echo.channel('masjids-system')
        .listen('TestNotificationEvent', (e) => {
            console.log('message: \n', e.message)
        })
})
</script>