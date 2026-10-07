<template>
    <div class="guide-content">
        <div ref="container" class="guide-fragment"></div>
        <p v-if="missingTask" role="status">This destination is not available in this guide.</p>
        <GuideViewer v-if="picture" :picture="picture" @close="picture = null" />
    </div>
</template>

<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import GuideViewer from '@/components/guides/GuideViewer.vue';
import fragmentStyles from '@/components/guides/guideContent.css?inline';
import { attachGuide } from '@/core/guides/guideRuntime';
import type { GuideRealm, GuideBook, GuidePage, GuideItem } from '@/core/guides/guidePaths';
const props = defineProps<{
    page: GuidePage; allowed: GuideBook[]; realm: GuideRealm; query: string; task?: string; faq?: string; theme: string;
    path: (book: string, task?: string, faq?: string) => string;
    fetchPicture: (path: string, signal: AbortSignal) => Promise<Blob>;
    navigate: (path: string) => void;
}>();
const emit = defineEmits<{ contents: [items: GuideItem[]]; palette: [tokens: Record<string, string>] }>();
const container = ref<HTMLElement | null>(null);
const picture = ref<{ src: string; alt: string; opener: HTMLElement } | null>(null);
const missingTask = ref(false);
let runtime: ReturnType<typeof attachGuide> | null = null;
let fragment: HTMLElement | null = null;
const applyTheme = (theme: string) => {
    runtime?.theme(theme);
    // Read the release's palette; never replace its declarations on .mg.
    const root = fragment?.querySelector<HTMLElement>('.mg');
    if (!root || typeof getComputedStyle !== 'function') return;
    const styles = getComputedStyle(root);
    const tokens: Record<string, string> = {};
    for (const name of ['paper', 'card', 'ink', 'soft', 'line', 'accent', 'accent-ink', 'ring']) {
        const value = styles.getPropertyValue(`--${name}`).trim();
        if (value) tokens[`--guide-${name}`] = value;
    }
    emit('palette', tokens);
};
onMounted(() => {
    // Shadow scope keeps app element/class rules out and release rules in.
    const shadow = container.value!.attachShadow({ mode: 'open' });
    const base = document.createElement('style'); base.textContent = fragmentStyles;
    const release = document.createElement('style'); release.textContent = props.page.css;
    fragment = document.createElement('div');
    // Validated HTML is data in one container, never Vue's compiler.
    fragment.innerHTML = props.page.html;
    shadow.append(base, release, fragment);
    runtime = attachGuide(fragment, {
        tasks: props.page.tasks, allowed: props.allowed.map(b => b.book), path: props.path,
        picture: props.fetchPicture, navigate: props.navigate, view: value => { picture.value = value; },
        contents: items => emit('contents', items),
    });
    applyTheme(props.theme); runtime.search(props.query);
    if (props.faq) missingTask.value = runtime.focusQuestion(props.faq) === false;
    else if (props.task) missingTask.value = runtime.focusTask(props.task) === false;
    else runtime.focusTop();
});
watch(() => props.theme, applyTheme);
watch(() => props.query, value => runtime?.search(value));
onBeforeUnmount(() => { runtime?.dispose(); runtime = null; fragment = null; });
</script>

<style scoped>
.guide-content, .guide-fragment { min-width: 0; overflow-wrap: anywhere; }
</style>
