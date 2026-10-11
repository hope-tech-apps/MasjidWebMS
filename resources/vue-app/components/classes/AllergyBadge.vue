<template>
    <span v-if="text" class="student-allergy-badge">
        <span role="button" tabindex="0" class="badge bg-warning-subtle text-warning-emphasis border"
              :title="text" :aria-label="`Allergy: ${text}`" :aria-expanded="expanded"
              @focus="expanded = true" @blur="expanded = false" @click.stop="expanded = true"
              @keydown.enter.stop.prevent="expanded = true" @keydown.space.stop.prevent="expanded = true"
              @keydown.esc.stop="expanded = false">Allergy</span>
        <span v-if="expanded" class="student-allergy-note alert alert-warning small mb-0" role="status">{{ text }}</span>
    </span>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
const props = defineProps<{ note?: { allergies?: string | null } | null }>();
const expanded = ref(false);
const text = computed(() => {
    const value = props.note?.allergies?.trim() ?? '';
    const none = value.replace(/^[\s\p{P}\p{S}]+|[\s\p{P}\p{S}]+$/gu, '').toLowerCase();
    return ['', 'none', 'no', 'n/a', 'na', 'nil', 'nothing', 'no allergies', 'none known'].includes(none) ? '' : value;
});
</script>

<style scoped>
.student-allergy-badge { display: inline-flex; flex-direction: column; align-items: flex-start; flex: 0 0 auto; }
.student-allergy-badge [role="button"] { cursor: pointer; white-space: nowrap; }
.student-allergy-badge [role="button"]:focus-visible { outline: 2px solid var(--bs-warning); outline-offset: 2px; }
.student-allergy-note { max-inline-size: 10rem; overflow-wrap: anywhere; padding: .5rem; margin-block-start: .25rem; }
@media (max-width: 390px) { .student-allergy-note { max-inline-size: 6rem; } }
</style>
