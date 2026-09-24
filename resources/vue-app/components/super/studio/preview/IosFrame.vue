<template>
    <DeviceStage :width="393" :height="852" :max-scale="maxScale" :label="`${displayName} iPhone app`">
        <div class="phone ios">
            <div class="island" aria-hidden="true"></div>

            <header class="home-header" :style="{ backgroundColor: primary, color: IOS_HOME_HEADER_INK }">
                <img v-if="logoUrl" :src="logoUrl" alt="" class="logo" />
                <span class="name">{{ displayName }}</span>
            </header>

            <div class="menu">
                <div class="menu-band" :style="{ backgroundColor: primary, color: menuBandInk }">{{ displayName }}</div>
                <div v-for="section in menuSections" :key="section.key" class="menu-section">
                    <span v-if="section.title" class="menu-section-title">{{ section.title }}</span>
                    <ul class="list-unstyled m-0">
                        <li v-for="item in section.items" :key="item.key" class="menu-item">
                            <span>{{ item.title }}</span>
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </li>
                    </ul>
                </div>
                <p v-if="!menuSections.length" class="empty">The menu is empty with these features.</p>
            </div>

            <nav class="tab-bar" aria-label="iPhone tab bar">
                <span v-for="(tab, index) in tabs" :key="tab.key" class="tab"
                    :style="{ color: index === 0 ? selectedTabInk : undefined }">
                    <i class="bi bi-app" aria-hidden="true"></i>
                    <span>{{ tab.title }}</span>
                </span>
            </nav>
        </div>
    </DeviceStage>
</template>

<script setup lang="ts">
/**
 * The iPhone app mockup, 393×852. The app itself is the same for every
 * organisation; what changes is drawn from the server's preview only:
 *
 *  - the tab bar is exactly `app.ios.tabs` (AppMenu::tabs, what /menu serves),
 *    in its order, first tab selected;
 *  - the menu (the side drawer, drawn below the home header so both show at
 *    once) is `app.ios.sections` (AppMenu::sections), under a band in the
 *    primary colour carrying the organisation's name;
 *  - the words are the app's own, looked up in core/studio/appLabels.ts, the
 *    one place the SPA copies a native string. A key with no label there is
 *    shown as the key, so a new menu item is visible rather than blank;
 *  - the home header is the primary colour with the app's hard-coded white
 *    text; the menu band's text and the selected tab's colour are the ones the
 *    server graded in `platform_contrast`, so the mockup and the contrast list
 *    cannot disagree.
 */
import DeviceStage from '@/components/super/studio/preview/DeviceStage.vue';
import { IOS_HOME_HEADER_INK, IOS_MENU_TITLES, IOS_TAB_TITLES, iosSectionTitle } from '@/core/studio/appLabels';
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

function contrastInk(key: string, fallback: string): string {
    return props.preview.platform_contrast?.find((row) => row.key === key)?.foreground ?? fallback;
}

const menuBandInk = computed(() => contrastInk('app.menu_band', NEUTRAL_INK));
const selectedTabInk = computed(() => contrastInk('ios.selected_tab', NEUTRAL_PRIMARY));

const tabs = computed(() => props.preview.app.ios.tabs.map((key) => ({ key, title: IOS_TAB_TITLES[key] ?? key })));

const menuSections = computed(() => props.preview.app.ios.sections
    .map((section) => ({
        key: section.key,
        title: iosSectionTitle(section.key, displayName.value),
        items: section.items.map((item) => ({ key: item.key, title: IOS_MENU_TITLES[item.key] ?? item.key })),
    }))
    .filter((section) => section.items.length > 0));
</script>

<style scoped>
.phone {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    background: #f2f2f7;
    color: #111;
    border: 10px solid #1c1c1e;
    border-radius: 54px;
    overflow: hidden;
    font-size: 16px;
}

.island {
    position: absolute;
    top: 10px;
    left: 50%;
    transform: translateX(-50%);
    width: 110px;
    height: 30px;
    background: #1c1c1e;
    border-radius: 20px;
    z-index: 2;
}

.home-header {
    flex: 0 0 auto;
    padding: 58px 20px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.logo {
    width: 44px;
    height: 44px;
    object-fit: contain;
    background: rgba(255, 255, 255, .9);
    border-radius: 10px;
    padding: 4px;
}

.name {
    font-weight: 700;
    font-size: 20px;
    line-height: 1.2;
}

.menu {
    flex: 1;
    overflow: hidden auto;
}

.menu-band {
    padding: 10px 20px;
    font-weight: 600;
}

.menu-section {
    padding: 12px 16px 0;
}

.menu-section-title {
    display: block;
    font-size: 13px;
    text-transform: uppercase;
    color: #6e6e73;
    margin: 0 4px 6px;
}

.menu-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fff;
    padding: 12px 14px;
    border-bottom: 1px solid #e5e5ea;
}

.menu-item:first-child {
    border-radius: 10px 10px 0 0;
}

.menu-item:last-child {
    border-radius: 0 0 10px 10px;
    border-bottom: 0;
}

.menu-item:only-child {
    border-radius: 10px;
}

.menu-item .bi {
    color: #c7c7cc;
}

.empty {
    padding: 20px;
    color: #6e6e73;
}

.tab-bar {
    flex: 0 0 auto;
    display: flex;
    justify-content: space-around;
    padding: 8px 8px 28px;
    background: rgba(249, 249, 249, .96);
    border-top: 1px solid #d1d1d6;
}

.tab {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    font-size: 11px;
    color: #8e8e93;
    min-width: 60px;
}

.tab .bi {
    font-size: 22px;
}
</style>
