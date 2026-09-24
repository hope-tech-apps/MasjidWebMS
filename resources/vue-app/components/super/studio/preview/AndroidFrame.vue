<template>
    <DeviceStage :width="412" :height="915" :max-scale="maxScale" :label="`${displayName} Android app`">
        <div class="phone android">
            <div class="status-bar" :style="{ backgroundColor: primary }" aria-hidden="true">
                <span class="camera"></span>
            </div>

            <header class="app-bar" :style="{ backgroundColor: primary, color: headerInk }">
                <img v-if="logoUrl" :src="logoUrl" alt="" class="logo" />
                <span class="name">{{ displayName }}</span>
            </header>

            <div class="body" aria-hidden="true">
                <div class="block tall"></div>
                <div class="block"></div>
                <div class="block"></div>
            </div>

            <nav class="bottom-nav" aria-label="Android tab bar">
                <span v-for="(tab, index) in tabs" :key="tab.key" class="tab" :class="{ selected: index === 0 }"
                    :style="{ color: index === 0 ? ANDROID_SELECTED_TAB : undefined }">
                    <i class="bi bi-circle-fill" aria-hidden="true"></i>
                    <span>{{ tab.title }}</span>
                </span>
            </nav>
        </div>
    </DeviceStage>
</template>

<script setup lang="ts">
/**
 * The Android app mockup, 412×915, drawn from the server's preview only:
 *
 *  - the bottom bar is exactly `app.android.tabs` (Home, then the legacy
 *    pivot rows StudioPreview::androidTabs derives, which /features serves),
 *    first tab selected in the app's hard-coded #00AA55, which no brand
 *    colour changes (R16);
 *  - the words are the app's own, from core/studio/appLabels.ts;
 *  - the header is the primary colour with the ink the server graded for it
 *    (`platform_contrast` android.home_header, DesignTokens' onPrimary).
 *
 * The screen body is left as grey blocks: the Android home screen is the same
 * for every organisation, and filling it would be inventing its content.
 */
import DeviceStage from '@/components/super/studio/preview/DeviceStage.vue';
import { ANDROID_SELECTED_TAB, ANDROID_TAB_TITLES } from '@/core/studio/appLabels';
import { StudioPreview } from '@/core/types/data/Studio';
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    preview: StudioPreview;
    logoUrl: string | null;
    maxScale?: number;
}>(), { maxScale: .6 });

/** Grey stand-ins while the draft has no colours (R25). */
const NEUTRAL_PRIMARY = '#8A8F98';
const NEUTRAL_INK = '#FFFFFF';

const displayName = computed(() => props.preview.org.name || 'Untitled draft');
const primary = computed(() => props.preview.web_tokens?.primary ?? NEUTRAL_PRIMARY);
const headerInk = computed(() => props.preview.platform_contrast?.find((row) => row.key === 'android.home_header')?.foreground ?? NEUTRAL_INK);

const tabs = computed(() => props.preview.app.android.tabs.map((key) => ({ key, title: ANDROID_TAB_TITLES[key] ?? key })));
</script>

<style scoped>
.phone {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    background: #f5f5f5;
    color: #111;
    border: 10px solid #202124;
    border-radius: 36px;
    overflow: hidden;
    font-size: 16px;
}

.status-bar {
    flex: 0 0 auto;
    height: 30px;
    display: flex;
    justify-content: center;
    align-items: center;
}

.camera {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #202124;
}

.app-bar {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, .15);
}

.logo {
    width: 40px;
    height: 40px;
    object-fit: contain;
    background: rgba(255, 255, 255, .9);
    border-radius: 8px;
    padding: 3px;
}

.name {
    font-weight: 600;
    font-size: 19px;
}

.body {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 16px;
}

.block {
    height: 90px;
    border-radius: 12px;
    background: #e3e3e6;
}

.block.tall {
    height: 180px;
}

.bottom-nav {
    flex: 0 0 auto;
    display: flex;
    justify-content: space-around;
    padding: 10px 6px 18px;
    background: #fff;
    border-top: 1px solid #e0e0e0;
}

.tab {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #5f6368;
    min-width: 64px;
}

.tab .bi {
    font-size: 18px;
}

.tab.selected {
    font-weight: 600;
}
</style>
