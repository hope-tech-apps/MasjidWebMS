<template>
    <section class="guide-screen" aria-labelledby="guide-title">
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
                <button class="btn btn-outline-secondary" type="button" :aria-pressed="theme === 'dark'" @click="toggleTheme">Dark guide</button>
            </div>
            <div class="guide-columns">
                <nav aria-label="Guide contents" class="guide-contents">
                    <h2>Contents</h2>
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
                <GuideContent :key="readId" :page="page" :allowed="books" :realm="realm" :query="query" :task="currentTask" :faq="currentFaq"
                              :theme="theme" :path="path" :fetch-picture="fetchPicture" :navigate="navigate" @contents="items = $event" />
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
};
const books = ref<GuideBook[]>([]);
const page = ref<GuidePage | null>(null);
const items = ref<GuideItem[]>([]);
const query = ref('');
const busy = ref(false);
const unavailable = ref(false);
const refused = ref(false);
const readId = ref(0);
const media = typeof window !== 'undefined' && typeof window.matchMedia === 'function' ? window.matchMedia('(prefers-color-scheme: dark)') : null;
const theme = ref(media?.matches ? 'dark' : 'light');
let themeChosen = false;
const systemTheme = () => { if (!themeChosen) theme.value = media?.matches ? 'dark' : 'light'; };
media?.addEventListener('change', systemTheme);
const toggleTheme = () => { themeChosen = true; theme.value = theme.value === 'dark' ? 'light' : 'dark'; };
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
    page.value = null; items.value = []; books.value = []; busy.value = true; unavailable.value = false; refused.value = false;
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
onBeforeUnmount(() => { generation++; controller?.abort(); media?.removeEventListener('change', systemTheme); });
</script>

<style scoped>
.guide-screen { padding: 1rem; max-width: 1400px; margin: auto; }
.guide-screen h1 { font-size: 1.6rem; }
.guide-tabs, .guide-tools { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-bottom: 1rem; }
.guide-tabs a { padding: .5rem .75rem; border: 1px solid #7a9690; border-radius: .4rem; }
.guide-tabs [aria-current] { font-weight: bold; background: #dcece8; }
.guide-tools input { flex: 1; min-width: 140px; }
.guide-columns { display: grid; grid-template-columns: minmax(180px, 240px) minmax(0, 1fr); gap: 1rem; }
.guide-contents h2 { font-size: 1.1rem; }
.guide-contents ol { list-style: none; padding: 0; }
.guide-contents li { margin-bottom: .6rem; }
.guide-contents a { display: inline-block; padding: .2rem; }
.guide-task { padding-left: .8rem; }
@media (max-width: 700px) { .guide-columns { grid-template-columns: minmax(0, 1fr); } .guide-contents { max-height: 15rem; overflow: auto; } }
</style>
