<template>
    <!-- The portal's language, on every screen that used to carry the two-way
         English/العربية button, in the same spot.
         A native <select> on purpose: six languages no longer fit a toggle,
         and on a phone the OS draws the list full-size in its own chrome — the
         one control on the page that works the same for a parent whatever
         language they read. Every option is written in ITSELF (اردو, not
         "Urdu"), because the parent looking for it is by definition one who
         cannot read the language the page is currently in. The globe is the
         same reason: a mark that means "language" without any words. -->
    <label class="family-lang-picker d-inline-flex align-items-center gap-1 mb-0 flex-shrink-0"
           :title="t('switch_lang_title')">
        <i class="bi bi-globe text-muted d-none d-sm-inline" aria-hidden="true"></i>
        <select class="form-select form-select-sm" :value="lang" :aria-label="label"
                @change="setLang(($event.target as HTMLSelectElement).value)">
            <option v-for="option in languages" :key="option.code" :value="option.code"
                    :lang="option.code" :dir="option.dir">{{ option.label }}</option>
        </select>
    </label>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useFamilyLang } from '@/views/family/familyI18n';

const { lang, setLang, t, languages } = useFamilyLang();

// Named in the current language AND in English, the way the translate button
// is, so a screen reader announces something a stranded parent recognises.
const label = computed(() => {
    const here = t('switch_lang_title');
    return lang.value === 'en' ? here : `${here} · Switch language`;
});
</script>

<style scoped>
.family-lang-picker .form-select {
    width: auto;
}

/* This app loads the LTR build of Bootstrap 5, whose .form-select pins its
   chevron to the physical right and reserves padding there. Under an RTL page
   that leaves the arrow on the wrong side of a right-aligned label, so the
   arrow and its padding follow the direction instead. */
[dir="rtl"] .family-lang-picker .form-select {
    background-position: left 0.5rem center;
    padding-left: 2rem;
    padding-right: 0.75rem;
}
</style>
