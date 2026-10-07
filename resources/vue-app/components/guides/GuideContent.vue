<template>
    <div class="guide-content">
        <component :is="'style'">{{ page.css }}</component>
        <div ref="container" class="guide-fragment"></div>
        <p v-if="missingTask" role="status">This destination is not available in this guide.</p>
        <GuideViewer v-if="picture" :picture="picture" @close="picture = null" />
    </div>
</template>

<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import GuideViewer from '@/components/guides/GuideViewer.vue';
import { attachGuide } from '@/core/guides/guideRuntime';
import type { GuideRealm, GuideBook, GuidePage, GuideItem } from '@/core/guides/guidePaths';
const props = defineProps<{
    page: GuidePage; allowed: GuideBook[]; realm: GuideRealm; query: string; task?: string; faq?: string; theme: string;
    path: (book: string, task?: string, faq?: string) => string;
    fetchPicture: (path: string, signal: AbortSignal) => Promise<Blob>;
    navigate: (path: string) => void;
}>();
const emit = defineEmits<{ contents: [items: GuideItem[]] }>();
const container = ref<HTMLElement | null>(null);
const picture = ref<{ src: string; alt: string; opener: HTMLElement } | null>(null);
const missingTask = ref(false);
let runtime: ReturnType<typeof attachGuide> | null = null;
onMounted(() => {
    // HTML is validated data. It enters only this container, never Vue's compiler.
    container.value!.innerHTML = props.page.html;
    runtime = attachGuide(container.value!, {
        tasks: props.page.tasks, allowed: props.allowed.map(b => b.book), path: props.path,
        picture: props.fetchPicture, navigate: props.navigate, view: value => { picture.value = value; },
        contents: items => emit('contents', items),
    });
    runtime.theme(props.theme); runtime.search(props.query);
    if (props.faq) missingTask.value = runtime.focusQuestion(props.faq) === false;
    else if (props.task) missingTask.value = runtime.focusTask(props.task) === false;
    else runtime.focusTop();
});
watch(() => props.theme, value => runtime?.theme(value));
watch(() => props.query, value => runtime?.search(value));
onBeforeUnmount(() => { runtime?.dispose(); runtime = null; });
</script>

<style>
.guide-fragment { overflow-wrap: anywhere; }
.guide-fragment .mg { max-width: 100%; min-width: 0; padding: 1rem; border-radius: .75rem; background: #fff; color: #172b2a; }
.guide-fragment .mg[data-theme="dark"] { background: #172b2a; color: #edf5f4; }
.guide-fragment .mg section, .guide-fragment .mg details { scroll-margin-top: 6rem; }
.guide-fragment .mg img { display: block; max-width: 100%; height: auto; }
.guide-fragment .mg .mg-picture { position: relative; max-width: 100%; display: inline-flex; align-items: center; justify-content: center; padding: 0; border: 1px solid #889b98; border-radius: .25rem; overflow: hidden; cursor: zoom-in; background: #e5eeec; color: #263e39; }
.guide-fragment .mg .mg-picture-caption { position: absolute; bottom: 0; right: 0; padding: .35rem .6rem; background: #172b2a; color: white; font-size: .8rem; pointer-events: none; }
.guide-fragment .mg .mg-picture-loading, .guide-fragment .mg .mg-picture-unavailable { cursor: default; }
.guide-fragment .mg .mg-picture-unavailable { font-size: .8rem; min-height: 1px; }
.guide-fragment .mg [hidden] { display: none !important; }
.guide-fragment .mg table { display: block; max-width: 100%; overflow-x: auto; }
.guide-fragment .mg :focus-visible { outline: 3px solid #168372; outline-offset: 3px; }
</style>
