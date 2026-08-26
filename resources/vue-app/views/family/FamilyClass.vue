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
                                    <div class="fw-semibold small">
                                        {{ thread.subject || 'Message' }}
                                        <span v-if="thread.unread" class="badge bg-success ms-1">New</span>
                                    </div>
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
                        <div v-for="m in openedMessages" :key="m.id"
                             :class="m.is_mine ? 'align-self-end text-end' : ''" style="max-width: 85%;">
                            <div class="text-muted small">
                                {{ m.is_mine ? 'You' : (m.author?.name || 'The school') }} · {{ when(m.created_at) }}
                            </div>
                            <div class="rounded px-3 py-2 d-inline-block text-start"
                                 :class="m.is_mine ? 'bg-success-subtle' : 'bg-light'"
                                 style="white-space: pre-wrap;">{{ m.body }}</div>
                        </div>
                    </div>

                    <div class="card-footer bg-white">
                        <div v-if="openedThread.is_closed" class="text-muted small">
                            The school has closed this conversation.
                        </div>
                        <template v-else>
                            <div v-if="replyError" class="alert alert-danger small py-2">{{ replyError }}</div>
                            <div class="d-flex gap-2 align-items-end">
                                <textarea v-model="replyBody" class="form-control" rows="2"
                                          placeholder="Write a reply…" @keydown.ctrl.enter="sendReply"></textarea>
                                <button class="btn btn-success" :disabled="!replyBody.trim() || sendingReply"
                                        @click="sendReply">
                                    <span v-if="sendingReply" class="spinner-border spinner-border-sm"></span>
                                    <span v-else>Send</span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </section>

            <!-- -------------------------------------------------- children -->
            <section v-else>
                <div v-for="child in group.children" :key="child.membership_id" class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <PersonAvatar
                                :avatar="child.contact?.avatar"
                                :first-name="child.contact?.first_name"
                                :last-name="child.contact?.last_name"
                                :size="52" />
                            <div>
                                <h2 class="h6 mb-0">{{ childName(child) }}</h2>
                                <div class="d-flex gap-3">
                                    <button class="btn btn-link btn-sm p-0 text-decoration-none"
                                            @click="avatarFor = child">
                                        Choose an avatar
                                    </button>
                                    <!-- Hand the phone over. The child gets a session
                                         of their own that cannot reach this portal. -->
                                    <button class="btn btn-link btn-sm p-0 text-decoration-none"
                                            :disabled="handingOver === child.membership_id"
                                            @click="handOver(child)">
                                        {{ handingOver === child.membership_id ? 'Starting…' : `Let ${childName(child).split(' ')[0]} choose` }}
                                    </button>
                                </div>
                            </div>
                        </div>

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

                        <h3 class="text-uppercase text-muted small">Arabic letters</h3>
                        <div v-if="letters[child.membership_id]" class="mb-3">
                            <div class="d-flex justify-content-between align-items-baseline">
                                <span class="small">{{ letters[child.membership_id].stage.label }}</span>
                                <span class="small text-muted">
                                    {{ letters[child.membership_id].totals.mastered }} of
                                    {{ letters[child.membership_id].totals.total }}
                                </span>
                            </div>
                            <div class="progress mt-1" style="height:8px;">
                                <div class="progress-bar bg-success"
                                     :style="{ width: lettersPercent(child.membership_id) + '%' }"></div>
                            </div>
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <span v-for="l in letters[child.membership_id].letters" :key="l.id"
                                      class="letter-chip"
                                      :class="`letter-chip--${l.status}`"
                                      :title="`${l.transliteration} — ${l.status.replace('_',' ')}`">
                                    {{ l.glyph }}
                                </span>
                            </div>
                        </div>
                        <p v-else class="text-muted small mb-3">Nothing recorded yet.</p>

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

        <!-- A parent choosing their own child's avatar. There is no student
             login, so this is where a child picks their face, with their parent. -->
        <div v-if="avatarFor" class="modal fade show d-block" tabindex="-1"
             style="background:rgba(0,0,0,.5)" @click.self="avatarFor = null">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Choose an avatar</h5>
                        <button type="button" class="btn-close" @click="avatarFor = null"></button>
                    </div>
                    <div class="modal-body">
                        <AvatarPicker
                            :masjid-id="masjidId"
                            :avatar="avatarFor.contact?.avatar"
                            :first-name="avatarFor.contact?.first_name"
                            :last-name="avatarFor.contact?.last_name"
                            :family-endpoint="`${base}/members/${avatarFor.membership_id}/avatar`"
                            @saved="onAvatarSaved" />
                    </div>
                </div>
            </div>
        </div>
    </template>

<script setup lang="ts">
import FamilyApiService, { rowsOf } from '@/core/services/FamilyApiService';
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import AvatarPicker from '@/components/common/AvatarPicker.vue';
import StudentApiService from '@/core/services/StudentApiService';
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
const avatarFor = ref<any>(null);
const handingOver = ref<number | null>(null);
const letters = ref<Record<string, any>>({});

const lettersPercent = (membershipId: number | string) => {
    const t = letters.value[membershipId]?.totals;
    return t && t.total ? Math.round((t.mastered / t.total) * 100) : 0;
};

/**
 * Hand the device to the child: ask the server for a session scoped to THIS
 * child, store it under its own key, and navigate into child mode.
 */
const handOver = async (child: any) => {
    handingOver.value = child.membership_id;
    try {
        const res = await FamilyApiService.post(
            `${base.value}/members/${child.membership_id}/student-session`, {}
        );
        const data = res.data?.data;
        StudentApiService.begin(data.token, {
            masjidId: String(masjidId.value),
            groupId: String(groupId.value),
            membershipId: String(child.membership_id),
            name: childName(child),
        });
        router.push(`/family/${masjidId.value}/student/${groupId.value}/${child.membership_id}`);
    } catch (e) {
        if (!fail(e)) error.value = 'That could not be started. Please try again.';
    } finally {
        handingOver.value = null;
    }
};

/** Write the new avatar back onto the child in place, so the card updates
 *  without refetching the whole class. */
const onAvatarSaved = (student: any) => {
    if (avatarFor.value && student?.contact) {
        avatarFor.value.contact.avatar = student.contact.avatar ?? null;
    }
    avatarFor.value = null;
};
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

const replyBody = ref('');
const sendingReply = ref(false);
const replyError = ref('');

const sendReply = async () => {
    const body = replyBody.value.trim();
    if (!body || !openedThread.value) return;

    sendingReply.value = true;
    replyError.value = '';
    try {
        const res = await FamilyApiService.post(
            `${base.value}/threads/${openedThread.value.id}/messages`,
            { body }
        );
        // Append the server's own copy rather than echoing the draft, so what
        // is on screen is what was actually stored.
        openedMessages.value.push(res.data?.data);
        replyBody.value = '';

        // The thread list's counts and unread flag are now stale.
        const t = await FamilyApiService.get(`${base.value}/threads`);
        threads.value = rowsOf(t.data?.data);
    } catch (e: any) {
        if (fail(e)) return;
        replyError.value = e?.response?.data?.message
            || e?.response?.data?.data?.body?.[0]
            || 'Your reply could not be sent. Please try again.';
    } finally {
        sendingReply.value = false;
    }
};

const openThread = async (thread: any) => {
    try {
        const res = await FamilyApiService.get(`${base.value}/threads/${thread.id}`);
        openedThread.value = res.data?.data?.thread ?? thread;
        replyBody.value = '';
        replyError.value = '';
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

            try {
                const l = await FamilyApiService.get(`${base.value}/members/${child.membership_id}/letters`);
                letters.value[child.membership_id] = l.data?.data ?? null;
            } catch {
                // A class with no Arabic work is not an error; the card simply
                // says nothing is recorded yet.
            }
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

<style scoped>
.letter-chip {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 8px; font-size: 17px; line-height: 1;
    background: var(--bs-tertiary-bg, #eceff1); color: #444;
}
.letter-chip--learning { background: rgba(255, 193, 7, .28); }
.letter-chip--mastered { background: rgba(25, 135, 84, .26); color: #0f5132; }
</style>

