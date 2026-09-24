<template>
    <div class="d-flex flex-column flex-md-row align-items-start justify-content-between gap-3">
        <div class="d-flex flex-column gap-1 min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="fw-semibold">{{ entry.label }}</span>
                <span v-if="entry.default_at_creation" class="badge text-bg-light border">On for a new organisation</span>
            </div>
            <span :id="`feature-help-${entry.key}`" class="small text-muted">{{ entry.turns_on }}</span>
            <div v-if="appItems.length || suggested" class="d-flex flex-wrap gap-2">
                <span v-if="appItems.length" class="chip app-chip">
                    <i class="bi bi-phone me-1" aria-hidden="true"></i>App: {{ appItems.join(', ') }}<template v-if="entry.app.tab">, on the tab bar</template>
                </span>
                <span v-if="suggested" class="chip suggest-chip">
                    <i class="bi bi-stars me-1" aria-hidden="true"></i>Suggested with {{ suggested }}
                </span>
            </div>
        </div>

        <div class="flex-shrink-0 form-check form-switch m-0 d-flex align-items-center gap-2">
            <input :id="`feature-${entry.key}`" class="form-check-input feature-switch" type="checkbox" role="switch"
                :checked="on" :disabled="disabled" :aria-describedby="`feature-help-${entry.key}`"
                @change="emit('toggle', ($event.target as HTMLInputElement).checked)" />
            <label :for="`feature-${entry.key}`" class="form-check-label small fw-semibold">
                <span class="visually-hidden">{{ entry.label }}: </span>{{ on ? 'On' : 'Off' }}
            </label>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * One served switch on Studio's feature step: the catalogue's label and its
 * `turns_on` line (what switching it ON gives, R9), an app chip naming the menu
 * entries it can show (the entry's `app.items`, AppMenu's own derivation, in
 * the app's words from core/studio/appLabels.ts), and whether a chosen
 * platform suggests it (`preselect_with`). The switch looks and behaves like
 * the SuperAdmin switch panel's (OrganisationSwitchesPanel), so the two screens
 * that set these read alike.
 */
import { IOS_MENU_TITLES } from '@/core/studio/appLabels';
import { StudioCatalogueEntry } from '@/core/types/data/Studio';
import { computed } from 'vue';

const props = defineProps<{
    entry: StudioCatalogueEntry;
    on: boolean;
    /** The chosen platforms that suggest this switch, by name; '' when none do. */
    suggested: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ toggle: [on: boolean] }>();

const appItems = computed(() => props.entry.app.items.map((item) => IOS_MENU_TITLES[item] ?? item));
</script>

<style scoped>
.min-w-0 {
    min-width: 0;
}

.chip {
    display: inline-flex;
    align-items: center;
    border-radius: 2rem;
    padding: .1rem .6rem;
    font-size: .8rem;
}

.app-chip {
    background: rgba(1, 177, 81, .1);
    color: #0b6b35;
}

.suggest-chip {
    background: rgba(13, 110, 253, .08);
    color: #0a4fb3;
}

.feature-switch {
    width: 2.5rem;
    height: 1.25rem;
    cursor: pointer;
}

.feature-switch:checked {
    background-color: var(--cgreen, #01b151);
    border-color: var(--cgreen, #01b151);
}

.feature-switch:focus-visible {
    outline: 2px solid var(--cgreen, #01b151);
    outline-offset: 2px;
}
</style>
