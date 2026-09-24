<template>
    <DeviceStage :width="size.width" :height="size.height" :max-scale="maxScale"
        :label="`${name || 'Untitled draft'} website, ${viewport === 'mobile' ? 'phone' : 'desktop'}`">
        <div class="web-frame" :class="[`vp-${viewport}`, { 'header-over': headerOver }]" :style="palette">
            <div class="web-chrome" aria-hidden="true">
                <span class="dot"></span><span class="dot"></span><span class="dot"></span>
                <span class="address">{{ host || 'No address yet' }}</span>
            </div>

            <div class="web-page">
                <header class="web-header">
                    <div class="brand">
                        <img v-if="logoUrl" :src="logoUrl" alt="" class="brand-logo" />
                        <span v-else-if="logoMissing" class="missing">No logo: the website needs one</span>
                        <span class="brand-name">{{ name || 'Untitled draft' }}</span>
                    </div>
                    <nav v-if="viewport === 'desktop'" class="web-nav" aria-label="Website menu">
                        <button v-for="page in menuPages" :key="page.slug" type="button" class="nav-link-btn"
                            :class="{ current: page.slug === shownPage?.slug }" :tabindex="interactive ? 0 : -1"
                            @click="show(page.slug)">
                            {{ page.title }}
                        </button>
                        <button v-for="page in buttonPages" :key="page.slug" type="button" class="nav-cta"
                            :tabindex="interactive ? 0 : -1" @click="show(page.slug)">
                            {{ page.title }}
                        </button>
                    </nav>
                    <i v-else class="bi bi-list menu-icon" aria-hidden="true"></i>
                </header>

                <main class="web-main">
                    <p v-if="!shownPage" class="empty">This layout writes no pages.</p>
                    <template v-else>
                        <template v-for="(section, index) in shownPage.sections" :key="section.slot">
                            <div v-if="!section.has_renderer" class="no-renderer">
                                The website cannot draw “{{ section.title }}” ({{ section.section_type }}) yet.
                            </div>
                            <section v-else class="web-section" :class="{ banner: index === 0, inactive: !section.is_active }">
                                <span v-if="!section.is_active" class="inactive-tag">Written switched off</span>
                                <h2 v-if="heading(section)" class="section-heading">{{ heading(section) }}</h2>
                                <p v-if="subheading(section)" class="section-sub">{{ subheading(section) }}</p>
                                <p v-if="bodyText(section)" class="section-text">{{ bodyText(section) }}</p>
                                <div v-if="links(section).length" class="section-links">
                                    <span v-for="(link, linkIndex) in links(section)" :key="linkIndex" class="link-pill">{{ link }}</span>
                                </div>
                                <span v-if="buttonText(section)" class="section-button">{{ buttonText(section) }}</span>
                                <div v-for="placeholder in openPlaceholders(section)" :key="placeholder.field" class="placeholder">
                                    <span class="placeholder-tag">{{ placeholder.essential ? 'To fill in' : 'Optional' }}</span>
                                    {{ placeholder.hint_text }}
                                </div>
                                <p v-if="isBare(section)" class="section-caption">{{ section.title }}</p>
                            </section>
                        </template>
                    </template>
                </main>

                <footer class="web-footer" :class="{ columns: footerColumns }">
                    <div class="footer-col">
                        <strong>{{ name || 'Untitled draft' }}</strong>
                        <span v-if="host">{{ host }}</span>
                    </div>
                    <div v-if="footerColumns" class="footer-col">
                        <span v-for="page in menuPages" :key="page.slug">{{ page.title }}</span>
                    </div>
                </footer>
            </div>
        </div>
    </DeviceStage>
</template>

<script setup lang="ts">
/**
 * The website mockup (docs/manara-studio-w1.md S5, D6): 1280×800 on a desktop
 * or 390×844 on a phone, drawn from the server's starter plan and nothing else.
 *
 *  - The pages and sections are the plan's (StudioPreview `web.pages`, which is
 *    StarterSite::plan(), what S8 writes). Nothing is typed in here: no page,
 *    no section type, no copy. A section shows the words its content already
 *    holds, and each open placeholder shows its admin-facing hint, so the
 *    mockup never fills a gap with words nobody gave (D8).
 *  - A section whose type the public website cannot draw (`has_renderer`
 *    false) is a red block, because the live site would show nothing there.
 *  - The header menu and the footer list only the pages the live site will
 *    serve (core/studio/sitePages.ts): a page planned inactive is left out.
 *  - A missing logo is a red notice where the logo goes: the website needs one
 *    (the logo rule).
 *  - The header and footer follow the preset's `theme_layout`, read the way
 *    the Brand Studio reads a saved theme (core/helpers/themeTokens.ts
 *    styleFromTokens), so the two screens name the renderer's variants alike.
 *  - The colours are `web_tokens`, DesignTokens as the renderer will get them.
 *    Until all four brand colours are chosen there are none (R25), and the
 *    frame is drawn in greys rather than in another client's palette.
 */
import DeviceStage from '@/components/super/studio/preview/DeviceStage.vue';
import { styleFromTokens } from '@/core/helpers/themeTokens';
import { buttonPages as siteButtonPages, menuPages as siteMenuPages } from '@/core/studio/sitePages';
import { StudioPlanPage, StudioPlanPlaceholder, StudioPlanSection } from '@/core/types/data/Studio';
import { computed, ref, watch } from 'vue';

const props = withDefaults(defineProps<{
    pages: StudioPlanPage[];
    themeLayout: Record<string, unknown> | null;
    tokens: Record<string, string> | null;
    name: string;
    host: string | null;
    logoUrl: string | null;
    /** True when the draft has no logo at all (not while one is still loading). */
    logoMissing: boolean;
    viewport?: 'desktop' | 'mobile';
    /** False for a thumbnail: the menu is drawn but takes no focus. */
    interactive?: boolean;
    maxScale?: number;
}>(), {
    viewport: 'desktop',
    interactive: true,
    maxScale: 1,
});

const SIZES = {
    desktop: { width: 1280, height: 800 },
    mobile: { width: 390, height: 844 },
} as const;

const size = computed(() => SIZES[props.viewport]);

/** Neutral greys, used only while the draft has no colours. */
const NEUTRAL: Record<string, string> = {
    primary: '#8A8F98',
    secondary: '#4B5563',
    accent: '#D1D5DB',
    background: '#FFFFFF',
    surface: '#FFFFFF',
    text: '#111827',
    textMuted: '#6B7280',
    border: '#E5E7EB',
    onPrimary: '#FFFFFF',
    onSecondary: '#FFFFFF',
    onAccent: '#111827',
};

/**
 * Each colour token as a CSS variable on the frame (`textMuted` becomes
 * `--w-text-muted`), the draft's tokens over the greys.
 */
const palette = computed(() => {
    const colours: Record<string, string> = { ...NEUTRAL, ...(props.tokens ?? {}) };
    return Object.fromEntries(Object.entries(colours)
        .map(([key, value]) => [`--w-${key.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}`, value]));
});

const style = computed(() => styleFromTokens({ layout: props.themeLayout ?? {} }));
const headerOver = computed(() => style.value.header !== 'default');
const footerColumns = computed(() => style.value.footer !== 'default');

const menuPages = computed(() => siteMenuPages(props.pages));
const buttonPages = computed(() => siteButtonPages(props.pages));

const shownSlug = ref<string | null>(null);
const shownPage = computed(() => props.pages.find((page) => page.slug === shownSlug.value) ?? props.pages[0] ?? null);

// A new plan (another preset, another client) opens on its first page again.
watch(() => props.pages.map((page) => page.slug).join('|'), () => { shownSlug.value = null; });

function show(slug: string) {
    if (props.interactive) shownSlug.value = slug;
}

/** The first of a section's content values that is words, or ''. */
function firstWords(...values: unknown[]): string {
    for (const value of values) {
        if (typeof value === 'string' && value.trim() !== '') return value;
    }
    return '';
}

// The content fields the section types share for their words (SectionType
// defaultContent). Read by name, never by section type, so any type that
// carries them shows them.
const heading = (section: StudioPlanSection) => firstWords(section.content.title, section.content.heading);
const subheading = (section: StudioPlanSection) => firstWords(section.content.subtitle, section.content.description);
const bodyText = (section: StudioPlanSection) => firstWords(section.content.text);
const buttonText = (section: StudioPlanSection) => firstWords(section.content.button_text);

/** The words of a section's list of links, when it has one. */
function links(section: StudioPlanSection): string[] {
    const list = section.content.links;
    if (!Array.isArray(list)) return [];
    return list
        .map((link) => (link && typeof link === 'object' ? (link as Record<string, unknown>).label : null))
        .filter((label): label is string => typeof label === 'string' && label !== '');
}

function openPlaceholders(section: StudioPlanSection): StudioPlanPlaceholder[] {
    return section.placeholders.filter((placeholder) => placeholder.open);
}

/** A section with nothing to show yet is captioned with its name, so the block is never blank. */
function isBare(section: StudioPlanSection): boolean {
    return !heading(section) && !subheading(section) && !bodyText(section)
        && !links(section).length && !buttonText(section) && !openPlaceholders(section).length;
}
</script>

<style scoped>
.web-frame {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    background: var(--w-background);
    color: var(--w-text);
    font-size: 16px;
    border: 1px solid #d0d4da;
    border-radius: 10px;
    overflow: hidden;
}

.web-chrome {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    background: #eceef1;
    border-bottom: 1px solid #d0d4da;
}

.web-chrome .dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #c4c8ce;
}

.web-chrome .address {
    margin-left: 12px;
    flex: 1;
    background: #fff;
    border-radius: 6px;
    padding: 3px 10px;
    font-size: 13px;
    color: #555;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.web-page {
    position: relative;
    flex: 1;
    overflow: hidden auto;
    display: flex;
    flex-direction: column;
}

.web-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 32px;
    background: var(--w-surface);
    border-bottom: 1px solid var(--w-border);
}

.header-over .web-header {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1;
    background: transparent;
    border-bottom-color: transparent;
    color: var(--w-on-primary);
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.brand-logo {
    height: 40px;
    max-width: 160px;
    object-fit: contain;
}

.brand-name {
    font-weight: 700;
    font-size: 18px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.missing {
    background: #dc3545;
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    border-radius: 4px;
    padding: 4px 8px;
    white-space: nowrap;
}

.web-nav {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.nav-link-btn {
    border: 0;
    background: transparent;
    color: inherit;
    font-size: 15px;
    padding: 6px 10px;
    border-radius: 6px;
}

.nav-link-btn.current {
    font-weight: 700;
    text-decoration: underline;
    text-underline-offset: 4px;
}

.nav-cta {
    border: 0;
    background: var(--w-primary);
    color: var(--w-on-primary);
    font-size: 15px;
    font-weight: 600;
    padding: 8px 16px;
    border-radius: 8px;
}

.menu-icon {
    font-size: 26px;
}

.web-main {
    flex: 1;
}

.web-section {
    position: relative;
    padding: 40px 48px;
    border-bottom: 1px solid var(--w-border);
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.vp-mobile .web-section {
    padding: 28px 20px;
}

.vp-mobile .web-header {
    padding: 12px 16px;
}

.web-section.banner {
    background: var(--w-primary);
    color: var(--w-on-primary);
    padding-top: 56px;
    padding-bottom: 56px;
}

.header-over .web-section.banner {
    padding-top: 110px;
}

.web-section.inactive {
    opacity: .55;
}

.section-heading {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
}

.banner .section-heading {
    font-size: 40px;
}

.section-sub {
    font-size: 18px;
    margin: 0;
    color: var(--w-text-muted);
}

.banner .section-sub {
    color: inherit;
    opacity: .9;
}

.section-text {
    margin: 0;
}

.section-links {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.link-pill {
    border: 1px solid var(--w-primary);
    color: var(--w-primary);
    border-radius: 999px;
    padding: 4px 14px;
    font-size: 14px;
}

.section-button {
    align-self: flex-start;
    background: var(--w-accent);
    color: var(--w-on-accent);
    font-weight: 600;
    padding: 10px 20px;
    border-radius: 8px;
}

.placeholder {
    border: 2px dashed currentColor;
    opacity: .75;
    border-radius: 8px;
    padding: 14px 16px;
    font-size: 14px;
}

.placeholder-tag,
.inactive-tag {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-right: 8px;
}

.inactive-tag {
    align-self: flex-start;
    background: #6c757d;
    color: #fff;
    border-radius: 4px;
    padding: 2px 6px;
}

.section-caption {
    margin: 0;
    color: var(--w-text-muted);
    font-style: italic;
}

.no-renderer {
    background: #dc3545;
    color: #fff;
    font-weight: 600;
    padding: 28px 48px;
    border-bottom: 1px solid #b02a37;
}

.web-footer {
    background: var(--w-secondary);
    color: var(--w-on-secondary);
    padding: 24px 48px;
    display: flex;
    gap: 48px;
    font-size: 14px;
}

.web-footer.columns {
    padding-top: 36px;
    padding-bottom: 36px;
}

.footer-col {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.vp-mobile .web-footer {
    flex-direction: column;
    gap: 16px;
    padding: 20px;
}

.empty {
    padding: 48px;
    color: var(--w-text-muted);
}
</style>
