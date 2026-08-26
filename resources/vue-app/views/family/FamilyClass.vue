<template>
    <div>
        <router-link :to="`/family/${masjidId}`" class="text-decoration-none small d-inline-block mb-3">
            &larr; All classes
        </router-link>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border text-success"></span></div>

        <div v-else-if="error" class="alert alert-danger">{{ error }}</div>

        <template v-else-if="group">
            <h1 class="h4 mb-1">{{ group.name }}</h1>
            <p v-if="group.description" class="text-muted small mb-3">{{ group.description }}</p>

            <ul class="nav nav-pills gap-1 mb-4">
                <li class="nav-item">
                    <button class="nav-link" :class="{ active: tab === 'story' }" @click="tab = 'story'">Class story</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" :class="{ active: tab === 'messages' }" @click="tab = 'messages'">Messages</button>
                </li>
                <li v-if="group.children?.length" class="nav-item">
                    <button class="nav-link" :class="{ active: tab === 'children' }" @click="tab = 'children'">
                        {{ group.children.length === 1 ? childName(group.children[0]) : 'My children' }}
                    </button>
                </li>
            </ul>

            <!-- ------------------------------------------------ class story -->
            <section v-if="tab === 'story'">
                <div v-if="!group.may_receive_feed" class="alert alert-warning">
                    The class story is hidden because your consent for class updates is not on file.
                    The school office can record it for you.
                </div>

                <div v-else-if="!posts.length" class="text-muted small">Nothing posted yet.</div>

                <div v-else class="d-flex flex-column gap-3">
                    <article v-for="post in posts" :key="post.id" class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h2 v-if="post.title" class="h6 mb-1">{{ post.title }}</h2>
                            <p class="text-muted small mb-2">
                                {{ post.author?.name || 'The school' }} · {{ when(post.created_at) }}
                            </p>
                            <p class="mb-2" style="white-space: pre-wrap;">{{ post.body }}</p>

                            <div v-if="post.attachments?.length" class="d-flex flex-wrap gap-2">
                                <FamilyAttachment v-for="a in post.attachments" :key="a.id"
                                                  :src="attachmentUrl(post.id, a.id)" :name="a.name" />
                            </div>

                            <!-- The API says so explicitly rather than serving a shorter list. -->
                            <p v-if="post.media_withheld" class="text-muted small fst-italic mb-0 mt-2">
                                Photos in this post are hidden because photo consent is not on file.
                            </p>
                        </div>
                    </article>
                </div>
            </section>

            <!-- -------------------------------------------------- messages -->
            <section v-else-if="tab === 'messages'">
                <div v-if="!threads.length" class="text-muted small">No messages yet.</div>

                <div v-else class="d-flex flex-column gap-2">
                    <button v-for="thread in threads" :key="thread.id" type="button"
                            class="card border-0 shadow-sm text-start"
                            @click="openThread(thread)">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-semibold small">{{ thread.subject || 'Message' }}</div>
                                    <div class="text-muted small">
                                        <span v-if="thread.about">About {{ thread.about.name || childName(thread.about) }} · </span>
                                        {{ thread.message_count }} message{{ thread.message_count === 1 ? '' : 's' }}
                                    </div>
                                </div>
                                <span class="text-muted small text-nowrap">{{ when(thread.latest_message_at || thread.created_at) }}</span>
                            </div>
                        </div>
                    </button>
                </div>

                <!-- one open conversation -->
                <div v-if="openedThread" class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <strong class="small">{{ openedThread.subject || 'Message' }}</strong>
                        <button class="btn-close" @click="openedThread = null"></button>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                        <div v-for="m in openedMessages" :key="m.id">
                            <div class="text-muted small">{{ m.author?.name || 'The school' }} · {{ when(m.created_at) }}</div>
                            <div style="white-space: pre-wrap;">{{ m.body }}</div>
                        </div>
                    </div>
                    <div class="card-footer bg-white text-muted small">
                        Replying from the portal is coming soon. For now, contact the school office directly.
                    </div>
                </div>
            </section>

            <!-- -------------------------------------------------- children -->
            <section v-else>
                <div v-for="child in group.children" :key="child.membership_id" class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h2 class="h6 mb-3">{{ childName(child) }}</h2>

                        <h3 class="text-uppercase text-muted small">Behaviour</h3>
                        <p v-if="!records[child.membership_id]?.awards?.length" class="text-muted small">Nothing recorded yet.</p>
                        <ul v-else class="list-unstyled mb-3">
                            <li v-for="a in records[child.membership_id].awards" :key="a.id" class="d-flex gap-2 align-items-baseline">
                                <span class="badge" :class="a.polarity === 'negative' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success-emphasis'">
                                    {{ a.points > 0 ? '+' : '' }}{{ a.points }}
                                </span>
                                <span class="small">
                                    {{ a.skill_label }}
                                    <span class="text-muted">· {{ when(a.awarded_at) }}</span>
                                    <span v-if="a.note" class="text-muted"> — {{ a.note }}</span>
                                </span>
                            </li>
                        </ul>

                        <h3 class="text-uppercase text-muted small">Qur'an</h3>
                        <p v-if="!records[child.membership_id]?.hifz?.length" class="text-muted small mb-0">Nothing recorded yet.</p>
                        <ul v-else class="list-unstyled mb-0">
                            <li v-for="h in records[child.membership_id].hifz" :key="h.id" class="small">
                                <span class="text-capitalize">{{ h.kind }}</span>: {{ ayah(h.from) }} &rarr; {{ ayah(h.to) }}
                                <span class="text-muted">· {{ h.quality }} · {{ when(h.recited_at) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>

<script setup lang="ts">
import FamilyApiService, { rowsOf } from '@/core/services/FamilyApiService';
import FamilyAttachment from '@/views/family/FamilyAttachment.vue';
import { useFamilyStore } from '@/stores/familyStore';
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();

const masjidId = computed(() => String(route.params.masjidId));
const groupId = computed(() => String(route.params.groupId));
const base = computed(() => `/api/family/masjids/${masjidId.value}/groups/${groupId.value}`);

const group = ref<any>(null);
const posts = ref<any[]>([]);
const threads = ref<any[]>([]);
const records = ref<Record<string, any>>({});
const openedThread = ref<any>(null);
const openedMessages = ref<any[]>([]);
const tab = ref<'story' | 'messages' | 'children'>('story');
const loading = ref(true);
const error = ref('');

const childName = (child: any) =>
    [child?.contact?.first_name ?? child?.first_name, child?.contact?.last_name ?? child?.last_name]
        .filter(Boolean).join(' ') || 'Student';

/** `from` / `to` arrive as {surah, surah_name, ayah, juz}, not a string. */
const ayah = (ref: any) => {
    if (!ref) return '';
    if (typeof ref === 'string') return ref;
    return `${ref.surah_name ?? 'Surah ' + ref.surah} ${ref.ayah}`;
};

const when = (iso: string | null) => {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
};

const attachmentUrl = (postId: number, attachmentId: number) =>
    `${base.value}/posts/${postId}/attachments/${attachmentId}`;

const fail = (e: any) => {
    if (familyStore.handleAuthFailure(e?.response?.status)) {
        router.replace(`/family/${masjidId.value}/sign-in`);
        return true;
    }
    return false;
};

const openThread = async (thread: any) => {
    try {
        const res = await FamilyApiService.get(`${base.value}/threads/${thread.id}`);
        openedThread.value = res.data?.data?.thread ?? thread;
        openedMessages.value = rowsOf(res.data?.data?.messages);
    } catch (e) {
        if (!fail(e)) error.value = 'That conversation could not be opened.';
    }
};

const loadChildRecords = async () => {
    for (const child of group.value?.children ?? []) {
        try {
            const [awards, hifz] = await Promise.all([
                FamilyApiService.get(`${base.value}/members/${child.membership_id}/awards`),
                FamilyApiService.get(`${base.value}/members/${child.membership_id}/hifz`),
            ]);
            records.value[child.membership_id] = {
                awards: rowsOf(awards.data?.data),
                hifz: rowsOf(hifz.data?.data),
            };
        } catch (e) {
            if (fail(e)) return;
            records.value[child.membership_id] = { awards: [], hifz: [] };
        }
    }
};

onMounted(async () => {
    try {
        const res = await FamilyApiService.get(base.value);
        group.value = res.data?.data ?? null;

        // The feed is consent-gated; asking for it without consent is a 403 the
        // parent has already been told about, so do not ask.
        if (group.value?.may_receive_feed) {
            const p = await FamilyApiService.get(`${base.value}/posts`);
            posts.value = rowsOf(p.data?.data);
        }

        const t = await FamilyApiService.get(`${base.value}/threads`);
        threads.value = rowsOf(t.data?.data);

        await loadChildRecords();
    } catch (e: any) {
        if (!fail(e)) error.value = 'We could not load this class just now. Please try again.';
    } finally {
        loading.value = false;
    }
});

watch(tab, () => { openedThread.value = null; });
</script>
