<template>
    <div v-if="enabled" ref="workspace" class="class-workspace">
        <button v-if="phone" ref="trigger" type="button" class="btn btn-outline-success class-menu-trigger"
                :aria-expanded="String(open)" :aria-controls="menuId" @click="show">Class menu</button>
        <div v-if="open && phone" class="class-menu-backdrop" data-class-backdrop @click="close()"></div>
        <aside v-if="!phone || open" :id="menuId" ref="panel" class="class-menu" :class="{ 'class-menu-open': phone }"
               :style="phone ? undefined : { '--class-menu-rest-top': `${restTop}px` }"
               :role="phone ? 'dialog' : undefined" :aria-modal="phone ? 'true' : undefined"
               :aria-label="phone ? 'Class menu' : undefined" tabindex="-1">
            <button v-if="phone" type="button" class="btn btn-outline-secondary mb-3" @click="close()">Close class menu</button>
            <nav aria-label="Class navigation">
                <div v-for="section in sections" :key="section.label" class="class-menu-section">
                    <h3 class="class-menu-heading" data-class-section>{{ section.label }}</h3>
                    <p v-if="section.label === 'Subjects' && !section.items.length" class="text-muted small mb-0">No subjects assigned.</p>
                    <a v-for="item in section.items" :key="item.key" :href="href(item)" data-class-choice
                       :aria-current="currentKey === item.key ? 'page' : undefined"
                       class="class-menu-line" @click="select($event, item)">{{ item.label }}</a>
                </div>
            </nav>
        </aside>
        <main ref="content" class="class-workspace-content" :inert="open && phone ? '' : undefined">
            <h2 ref="heading" class="h4 mb-3" tabindex="-1">{{ title }}</h2>
            <p v-if="notice" role="status" class="text-muted small">{{ notice }}</p>
            <p v-if="busy" role="status" class="text-muted">Loading subject…</p>
            <slot v-else />
        </main>
    </div>
    <slot v-else />
</template>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { focusableIn, trapTab } from '@/core/helpers/focusTrap';
import type { ClassChoice, ClassSection } from '@/composables/useClassSubjects';

const props = defineProps<{
    enabled: boolean; sections: ClassSection[]; currentKey: string; title: string;
    notice: string; busy: boolean; href: (item: ClassChoice) => string;
}>();
const emit = defineEmits<{ choose: [choice: ClassChoice] }>();
const menuId = `class-menu-${useId()}`;
const open = ref(false);
const phone = ref(false);
const trigger = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const workspace = ref<HTMLElement | null>(null);
const restTop = ref(80);
const content = ref<HTMLElement | null>(null);
const heading = ref<HTMLElement | null>(null);
let media: MediaQueryList | null = null;
let previousFocus: HTMLElement | null = null;
let previousOverflow = '';
let background: HTMLElement[] = [];
let listening = false;
let mounted = false;

// Same local drawer behavior as DashboardAside: a phone choice closes the panel.
// Its fixed dashboard IDs cannot scope a second drawer, so this shell owns refs.
const restoreBackground = () => {
    for (const node of background) node.removeAttribute('inert');
    background = [];
    document.body.style.overflow = previousOverflow;
};
const close = (returnFocus = true) => {
    if (!open.value) return;
    open.value = false;
    restoreBackground();
    if (returnFocus) nextTick(() => { if (mounted) previousFocus?.focus(); });
};
const show = async () => {
    if (open.value || !props.enabled || !phone.value) return;
    previousFocus = document.activeElement as HTMLElement | null;
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    open.value = true;
    await nextTick();
    if (!mounted || !open.value) return;
    // Inert the surrounding header/global rail as well as this page's content.
    let node = panel.value?.parentElement;
    while (node && node !== document.body) {
        for (const sibling of Array.from(node.parentElement?.children ?? [])) {
            if (sibling !== node && sibling instanceof HTMLElement && !sibling.hasAttribute('inert')) {
                sibling.setAttribute('inert', ''); background.push(sibling);
            }
        }
        node = node.parentElement;
    }
    await revealSelection();
    (panel.value && focusableIn(panel.value)[0] || panel.value)?.focus();
};
const keydown = (event: KeyboardEvent) => {
    if (!open.value || !phone.value) return;
    if (event.key === 'Escape') { event.preventDefault(); close(); }
    else trapTab(event, panel.value);
};
const containFocus = (event: FocusEvent) => {
    if (open.value && panel.value && !panel.value.contains(event.target as Node)) (focusableIn(panel.value)[0] ?? panel.value).focus();
};
const select = (event: MouseEvent, item: ClassChoice) => {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button > 0) return;
    event.preventDefault();
    close(false);
    emit('choose', item);
    nextTick(() => { if (mounted) heading.value?.focus(phone.value ? undefined : { preventScroll: true }); });
};
// Adjust the menu's own scroll position. scrollIntoView would also move the page.
const revealSelection = async () => {
    await nextTick();
    if (!mounted || !props.enabled || !panel.value) return;
    const selected = Array.from(panel.value.querySelectorAll<HTMLElement>('[data-class-choice]'))
        .find(node => node.getAttribute('aria-current') === 'page');
    if (!selected) return;
    const bounds = panel.value.getBoundingClientRect();
    const line = selected.getBoundingClientRect();
    if (line.top < bounds.top) panel.value.scrollTop -= bounds.top - line.top;
    else if (line.bottom > bounds.bottom) panel.value.scrollTop += line.bottom - bounds.bottom;
};
// The sticky top is only the scrolled position. At rest the class header sits
// above this workspace, so reserve its measured space before enabling menu scroll.
const measureRest = async () => {
    await nextTick();
    if (!mounted || !props.enabled || phone.value || !workspace.value) return;
    restTop.value = Math.max(80, workspace.value.getBoundingClientRect().top + (window.scrollY || 0));
};
const resize = () => { phone.value = media?.matches ?? false; if (!phone.value) close(); measureRest().then(revealSelection); };
const start = () => {
    if (listening || !props.enabled) return;
    media = window.matchMedia('(max-width: 767.98px)'); resize();
    media.addEventListener('change', resize);
    window.addEventListener('resize', resize);
    document.addEventListener('keydown', keydown);
    document.addEventListener('focusin', containFocus);
    listening = true;
};
const stop = () => {
    close();
    media?.removeEventListener('change', resize); media = null;
    if (listening) {
        document.removeEventListener('keydown', keydown);
        document.removeEventListener('focusin', containFocus);
    }
    if (listening) window.removeEventListener('resize', resize);
    listening = false;
};
onMounted(() => { mounted = true; start(); revealSelection(); });
watch(() => props.enabled, enabled => { if (mounted) enabled ? start() : stop(); });
watch(() => [props.currentKey, props.busy, props.enabled], async () => {
    await measureRest();
    await revealSelection();
    if (props.enabled && !props.busy) { await nextTick(); if (mounted) heading.value?.focus(phone.value ? undefined : { preventScroll: true }); }
});
onBeforeUnmount(() => { mounted = false; stop(); });
</script>

<style scoped>
.class-workspace { display: grid; grid-template-columns: 12rem minmax(0, 1fr); gap: 1.5rem; align-items: start; }
.class-menu { min-width: 0; padding: .75rem; border: 1px solid var(--bs-border-color, #ddd); border-radius: .5rem; background: white; }
.class-menu-section + .class-menu-section { margin-top: .5rem; }
.class-menu-heading { font-size: .85rem; font-weight: 700; margin: 0 0 .4rem; color: var(--bs-secondary-color, #555); }
.class-menu-line { display: block; padding: .25rem .5rem; min-height: 32px; line-height: 1.4; color: #198754; border-radius: .25rem; text-decoration: none; overflow-wrap: anywhere; }
.class-menu-line[aria-current="page"] { background: #e8f3ed; font-weight: 700; }
.class-menu-line:focus-visible, .class-menu-trigger:focus-visible { outline: 2px solid #198754; outline-offset: 2px; }
.class-workspace-content > h2:focus { outline: none; }
.class-workspace-content { min-width: 0; overflow-wrap: anywhere; }
.class-workspace-content :deep(.table-responsive) { max-width: 100%; }
.class-workspace-content :deep(input), .class-workspace-content :deep(select), .class-workspace-content :deep(textarea) { max-width: 100%; }
.class-menu-trigger { justify-self: start; min-height: 44px; }
.class-menu-backdrop { position: fixed; inset: 0; background: rgb(0 0 0 / 45%); z-index: 1090; }
@media (min-width: 768px) {
    .class-menu { position: sticky; top: var(--class-menu-top, 80px); max-height: calc(100dvh - max(var(--class-menu-rest-top, 80px), var(--class-menu-top, 80px)) - 1rem); overflow-y: auto; overscroll-behavior: contain; }
}
@media (pointer: coarse) { .class-menu-line { min-height: 44px; } }
@media (max-width: 767.98px) {
    .class-menu-line { min-height: 44px; padding-top: .5rem; padding-bottom: .5rem; }
    .class-workspace { grid-template-columns: minmax(0, 1fr); gap: 1rem; }
    .class-menu-open { position: fixed; inset: 0 auto 0 0; width: min(19rem, 90vw); max-width: 100vw; border-radius: 0; overflow-y: auto; z-index: 1091; animation: class-slide .18s ease-out; }
    .class-workspace-content :deep(.btn) { min-height: 44px; }
    .class-workspace-content :deep(.form-control), .class-workspace-content :deep(.form-select) { font-size: 16px; }
}
@keyframes class-slide { from { transform: translateX(-100%); } to { transform: translateX(0); } }
@media (prefers-reduced-motion: reduce) { .class-menu-open { animation: none; } }
</style>
