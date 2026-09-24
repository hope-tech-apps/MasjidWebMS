<template>
    <StudioPanel title="About" note="Only what the client told you.">
        <div class="studio-field">
            <label for="studio-about">About</label>
            <textarea id="studio-about" v-model="content.about" rows="4" maxlength="5000" class="dashboard-input"></textarea>
        </div>
        <div class="d-flex flex-column flex-md-row gap-3">
            <div class="studio-field w-100">
                <label for="studio-mission">Mission</label>
                <textarea id="studio-mission" v-model="content.mission" rows="3" maxlength="5000" class="dashboard-input"></textarea>
            </div>
            <div class="studio-field w-100">
                <label for="studio-vision">Vision</label>
                <textarea id="studio-vision" v-model="content.vision" rows="3" maxlength="5000" class="dashboard-input"></textarea>
            </div>
        </div>
    </StudioPanel>
</template>

<script setup lang="ts">
/**
 * Foundation's About panel: the `content` section, which Step 3 publishes and
 * the starter pages quote (StudioPreview FACT_KEYS). The client's own words
 * only; Studio writes nothing here for them (docs/manara-studio.md D8).
 */
import StudioPanel from '@/components/super/studio/foundation/StudioPanel.vue';
import { useStudioDraftStore } from '@/stores/super/studioDraftStore';
import { computed } from 'vue';

const store = useStudioDraftStore();
const content = computed(() => store.answers.content);
</script>
