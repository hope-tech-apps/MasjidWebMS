<template>
    <section class="guide-screen" :style="palette" aria-labelledby="guide-title">
        <h1 id="guide-title">{{ page?.title || 'Help' }}</h1>
        <p v-if="busy" role="status">Loading guide…</p>
        <p v-else-if="unavailable" role="status">The guide is not available yet.</p>
        <p v-else-if="refused" role="status">This guide is not available for your account.</p>
        <template v-else-if="page">
            <nav v-if="books.length > 1" aria-label="Choose guide" class="guide-tabs">
                <a v-for="book in books" :key="book.book" :href="path(book.book)" :aria-current="book.book === currentBook ? 'page' : undefined"
                   @click.prevent="navigate(path(book.book))">{{ book.title }}</a>
            </nav>
            <div class="guide-tools">
                <label for="guide-search">Search tasks</label>
                <input id="guide-search" v-model="query" type="search" class="form-control" placeholder="Find a task">
                <button class="btn btn-outline-secondary" type="button" @click="query = ''">Clear search</button>
                <button class="btn btn-outline-secondary" type="button" :aria-pressed="String(theme === 'dark')" @click="toggleTheme">Dark guide: {{ theme === 'dark' ? 'on' : 'off' }}</button>
            </div>
            <div class="guide-columns">
                <details class="guide-contents-panel" :open="contentsOpen" @toggle="contentsToggled">
                    <summary>Contents</summary>
                    <nav aria-label="Guide contents" class="guide-contents">
                    <p v-if="query.trim() && !matchingTasks.length && !faqs.length" role="status">No results. Try another word.</p>
                    <ol>
                        <li v-for="chapter in chapters" :key="chapter.id">
                            <a :href="path(currentBook, chapter.id)" @click.prevent="navigate(path(currentBook, chapter.id))">{{ chapter.title }}</a>
                            <ol>
                                <li v-for="task in chapter.tasks" :key="task.id" class="guide-task">
                                    <a :href="path(currentBook, task.id)" @click.prevent="navigate(path(currentBook, task.id))">{{ task.title }}</a>
                                </li>
                            </ol>
                        </li>
                    </ol>
                    <template v-if="faqs.length">
                        <h2>Common questions</h2>
                        <ul><li v-for="item in faqs" :key="item.id"><a :href="path(currentBook, undefined, item.faq)" @click.prevent="navigate(path(currentBook, undefined, item.faq))">{{ item.title }}</a></li></ul>
                    </template>
                    </nav>
                </details>
                <GuideContent :key="readId" :page="page" :allowed="books" :realm="realm" :query="query" :task="currentTask" :faq="currentFaq"
                              :theme="theme" :path="path" :fetch-picture="fetchPicture" :navigate="navigate" @contents="items = $event" @palette="palette = $event" />
            </div>
        </template>
    </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';
import GuideApiService from '@/core/services/GuideApiService';
import GuideContent from '@/components/guides/GuideContent.vue';
import { guidePath } from '@/core/guides/guidePaths';
import type { GuideRealm, GuideBook, GuidePage, GuideItem } from '@/core/guides/guidePaths';
const props = defineProps<{ realm: GuideRealm }>();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const currentBook = computed(() => String(route.params.book || (props.realm === 'admin' ? 'admin' : props.realm)));
const currentTask = computed(() => route.params.task ? String(route.params.task) : undefined);
const currentFaq = computed(() => typeof route.query.faq === 'string' ? route.query.faq : undefined);
const path = (book: string, task?: string, faq?: string) => guidePath(props.realm, book, task, faq);
const navigationRead = ref(0);
const navigate = (target: string) => {
    const sameAddress = target === route.fullPath;
    query.value = ''; router.push(target);
    if (sameAddress) navigationRead.value++;
    if (compactMedia?.matches) contentsOpen.value = false;
};
const books = ref<GuideBook[]>([]);
const page = ref<GuidePage | null>(null);
const items = ref<GuideItem[]>([]);
const query = ref('');
const busy = ref(false);
const unavailable = ref(false);
const refused = ref(false);
const readId = ref(0);
const palette = ref<Record<string, string>>({});
const media = typeof window !== 'undefined' && typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null;
const compactMedia = typeof window !== 'undefined' && typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 700px)') : null;
const contentsOpen = ref(!compactMedia?.matches);
const contentsToggled = (event: Event) => { contentsOpen.value = (event.target as HTMLDetailsElement).open; };
const resizeContents = () => { contentsOpen.value = !compactMedia?.matches; };
// Safari/iOS 12 use the older MediaQueryList listener API.
const listen = (query: MediaQueryList | null, callback: () => void) => {
    if (query?.addEventListener) { query.addEventListener('change', callback); return () => query.removeEventListener('change', callback); }
    query?.addListener(callback); return () => query?.removeListener(callback);
};
const stopCompact = listen(compactMedia, resizeContents);
const preferredTheme = () => {
    if (typeof document !== 'undefined') {
        for (const element of [document.body, document.documentElement]) {
            for (const attribute of ['data-bs-theme', 'data-theme']) {
                const value = element?.getAttribute(attribute);
                if (value === 'light' || value === 'dark') return value;
            }
        }
        // The actual staff chrome is a fixed light palette, even on a dark OS.
        if (document.body?.classList.contains('mn-app')) return 'light';
    }
    return media?.matches ? 'dark' : 'light';
};
const themeKey = 'MANARA_GUIDE_THEME';
let savedTheme: string | null = null;
try { savedTheme = localStorage.getItem(themeKey); } catch { /* Storage can be unavailable. */ }
let themeChosen = savedTheme === 'light' || savedTheme === 'dark';
const theme = ref(themeChosen ? savedTheme! : preferredTheme());
const systemTheme = () => { if (!themeChosen) theme.value = preferredTheme(); };
const stopSystem = listen(media, systemTheme);
const themeObserver = typeof MutationObserver !== 'undefined' ? new MutationObserver(systemTheme) : null;
for (const element of [document.body, document.documentElement]) {
    if (element) themeObserver?.observe(element, { attributes: true, attributeFilter: ['class', 'data-bs-theme', 'data-theme'] });
}
const toggleTheme = () => {
    themeChosen = true; theme.value = theme.value === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(themeKey, theme.value); } catch { /* Keep the choice for this screen. */ }
};
const matchingTasks = computed(() => items.value.filter(item => item.kind === 'task' && `${item.title} ${item.text}`.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())));
const chapters = computed(() => items.value.filter(item => item.kind === 'chapter').map(chapter => ({
    ...chapter, tasks: matchingTasks.value.filter(task => task.chapter === chapter.id),
})).filter(chapter => !query.value.trim() || chapter.tasks.length));
const faqs = computed(() => items.value.filter(item => item.kind === 'faq' && `${item.title} ${item.text}`.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())));
const organisationId = computed(() => props.realm === 'lunch' ? auth.user?.masjid?.id : auth.dashboardMasjidId);
let controller: AbortController | null = null;
let generation = 0;
const fetchPicture = ref<(path: string, signal: AbortSignal) => Promise<Blob>>(async () => { throw new Error('Guide unavailable'); });
watch(() => [route.fullPath, navigationRead.value, organisationId.value, auth.user?.type, auth.user?.id, auth.token, auth.isAuthenticated], async () => {
    const run = ++generation;
    controller?.abort(); controller = new AbortController();
    const signal = controller.signal;
    page.value = null; palette.value = {}; items.value = []; books.value = []; busy.value = true; unavailable.value = false; refused.value = false;
    const id = organisationId.value;
    const book = currentBook.value;
    const base = `/api/${props.realm}/masjids/${id}/guides`;
    try {
        if (!id || !auth.isAuthenticated) throw new Error('Guide unavailable');
        const listing = await GuideApiService.json(base, signal);
        if (run !== generation) return;
        books.value = listing.data;
        if (!books.value.length) { unavailable.value = true; return; }
        if (!books.value.some(item => item.book === book)) { refused.value = true; return; }
        const response = await GuideApiService.json(`${base}/${encodeURIComponent(book)}`, signal);
        if (run !== generation) return;
        const release = response.data as GuidePage;
        // Capture book, tenant and version now; a later navigation cannot change old picture requests.
        fetchPicture.value = (file, pictureSignal) => GuideApiService.picture(`${base}/${encodeURIComponent(book)}/${release.version}/pictures/${file.split('/').map(encodeURIComponent).join('/')}`, pictureSignal);
        readId.value++; page.value = release;
    } catch { if (run === generation) unavailable.value = true; }
    finally { if (run === generation) busy.value = false; }
}, { immediate: true });
onBeforeUnmount(() => { generation++; controller?.abort(); stopSystem(); stopCompact(); themeObserver?.disconnect(); });
</script>

<style scoped>
.guide-screen { padding: 1rem; max-width: 1400px; margin: auto; border-radius: .75rem; background: var(--guide-paper, var(--mn-surface, #fff)); color: var(--guide-ink, var(--mn-ink, #172b2a)); }
.guide-screen > h1 { font-size: 1.6rem; color: inherit; }
.guide-tabs, .guide-tools { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-bottom: 1rem; }
.guide-tabs a { padding: .5rem .75rem; border: 1px solid var(--guide-line, var(--mn-line)); border-radius: .4rem; color: var(--guide-accent, var(--mn-brand-strong)); text-decoration: none; }
.guide-tabs [aria-current] { font-weight: bold; background: var(--guide-card, var(--mn-brand-soft)); }
.guide-tools input { flex: 1; min-width: 140px; }
.guide-screen .guide-tools .form-control, .guide-screen .guide-tools .btn { color: var(--guide-ink, var(--mn-ink)); background: var(--guide-card, var(--mn-surface)); border-color: var(--guide-line, var(--mn-field)); }
.guide-tools .form-control::placeholder { color: var(--guide-soft, var(--mn-muted)); }
.guide-screen .guide-tools .btn:hover { background: var(--guide-paper, var(--mn-hover)); }
.guide-screen :focus-visible { outline: 3px solid var(--guide-ring, var(--mn-brand-strong)); outline-offset: 3px; }
.guide-columns { display: grid; grid-template-columns: minmax(180px, 240px) minmax(0, 1fr); gap: 1rem; }
.guide-contents-panel { align-self: start; min-width: 0; border: 1px solid var(--guide-line, var(--mn-line)); border-radius: .75rem; background: var(--guide-card, var(--mn-surface)); }
.guide-contents-panel > summary { padding: .75rem; font-weight: 600; cursor: pointer; }
.guide-contents { padding: 0 .5rem .5rem; max-height: calc(100vh - 14rem); overflow: auto; font-size: .9rem; }
.guide-contents h2 { font-size: 1rem; padding: .5rem; color: inherit; }
.guide-contents ol, .guide-contents ul { list-style: none; padding: 0; margin: 0; }
.guide-contents li { margin-bottom: .2rem; }
.guide-contents a { display: block; padding: .4rem .5rem; border-radius: .35rem; color: inherit; text-decoration: none; line-height: 1.35; }
.guide-contents > ol > li > a { font-weight: 600; }
.guide-contents a:hover { background: var(--guide-paper, var(--mn-hover)); color: var(--guide-accent, var(--mn-brand-strong)); text-decoration: underline; }
.guide-task { padding-left: .8rem; }
@media (max-width: 700px) { .guide-screen { padding: .75rem; } .guide-columns { grid-template-columns: minmax(0, 1fr); } .guide-contents { max-height: 50vh; } }
</style>
