<template>
    <div>
        <router-link to="/teacher" class="text-decoration-none small d-inline-block mb-3">
            &larr; My Classes
        </router-link>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border text-success"></span></div>

        <div v-else-if="error" class="alert alert-danger">
            {{ error }}
            <button class="btn btn-sm btn-outline-danger ms-3" @click="loadGroup">Retry</button>
        </div>

        <template v-else-if="group">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h1 class="h4 mb-0">{{ group.name }}</h1>
                <span v-if="group.kind" class="badge bg-light text-dark border text-capitalize">{{ group.kind }}</span>
                <span v-if="group.is_active === false" class="badge bg-secondary-subtle text-secondary">Inactive</span>
            </div>
            <p v-if="group.description" class="text-muted small mb-3">{{ group.description }}</p>

            <ul class="nav nav-tabs mb-4 flex-nowrap overflow-auto">
                <li v-for="t in tabs" :key="t.key" class="nav-item">
                    <button type="button" class="nav-link text-nowrap"
                            :class="{ active: activeTab === t.key }" @click="activeTab = t.key">
                        <i :class="`bi ${t.icon} me-1`"></i>{{ t.label }}
                    </button>
                </li>
            </ul>

            <!-- ============================================ ROSTER (read only) -->
            <section v-if="activeTab === 'roster'">
                <p class="text-muted small">
                    Enrolment is managed by the school office. You can update a student's avatar,
                    but not add or remove students.
                </p>
                <div v-if="!students.length" class="text-muted small">No students on this roster yet.</div>
                <div v-else class="list-group">
                    <div v-for="s in students" :key="s.membership_id"
                         class="list-group-item d-flex align-items-center gap-3">
                        <PersonAvatar :avatar="s.contact?.avatar"
                                      :first-name="s.contact?.first_name" :last-name="s.contact?.last_name" :size="40" />
                        <div class="flex-grow-1">
                            <div class="fw-semibold small">{{ name(s.contact) }}</div>
                            <div v-if="guardianNames(s)" class="text-muted small">
                                Guardians: {{ guardianNames(s) }}
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" @click="avatarFor = s">
                            <i class="bi bi-person-badge me-1"></i>Avatar
                        </button>
                    </div>
                </div>
            </section>

            <!-- ==================================================== LETTERS -->
            <section v-else-if="activeTab === 'letters'">
                <!-- The class's Arabic stage. -->
                <div class="card border-0 bg-light mb-3">
                    <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
                        <div>
                            <div class="fw-semibold">{{ currentStageLabel || 'Arabic stage' }}</div>
                            <div v-if="tracker?.stage?.summary" class="text-muted small">{{ tracker.stage.summary }}</div>
                        </div>
                        <div v-if="stageOptions.length" class="d-flex align-items-center gap-2">
                            <label class="small text-muted mb-0">This class is on</label>
                            <select class="form-select form-select-sm" style="width:auto"
                                    :value="currentStageId" :disabled="savingStage"
                                    @change="setStage(($event.target as HTMLSelectElement).value)">
                                <option v-for="st in stageOptions" :key="st.id" :value="st.id">{{ st.label }}</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div v-if="stageNote" class="alert alert-info py-2 small">{{ stageNote }}</div>

                <!-- Student list -->
                <div v-if="!selected" class="list-group">
                    <button v-for="s in students" :key="s.membership_id" type="button"
                            class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                            @click="openLetters(s)">
                        <PersonAvatar :avatar="s.contact?.avatar"
                                      :first-name="s.contact?.first_name" :last-name="s.contact?.last_name" :size="38" />
                        <span class="fw-semibold small flex-grow-1">{{ name(s.contact) }}</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </button>
                    <div v-if="!students.length" class="text-muted small p-3">No students on this roster yet.</div>
                </div>

                <!-- One child's tracker -->
                <div v-else>
                    <button class="btn btn-link px-0 text-decoration-none mb-2" @click="selected = null">
                        &larr; All students
                    </button>

                    <div v-if="trackerLoading" class="text-center py-4"><span class="spinner-border text-success"></span></div>
                    <template v-else-if="tracker">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <PersonAvatar :avatar="selected.contact?.avatar"
                                          :first-name="selected.contact?.first_name"
                                          :last-name="selected.contact?.last_name" :size="48" />
                            <div>
                                <div class="fw-semibold">{{ name(selected.contact) }}</div>
                                <div class="text-muted small">
                                    {{ tracker.totals?.mastered ?? 0 }} of {{ tracker.totals?.total ?? 0 }} mastered
                                </div>
                            </div>
                        </div>

                        <!-- RTL: the alphabet begins at the top RIGHT and runs leftward. -->
                        <div class="d-flex flex-wrap gap-2 mb-3" dir="rtl">
                            <button v-for="l in tracker.letters" :key="l.id" type="button"
                                    class="letter-tile" :class="`letter-tile--${l.status}`"
                                    @click="openLetter = openLetter === l.id ? null : l.id">
                                <span class="letter-tile__glyph">{{ l.glyph }}</span>
                                <span class="letter-tile__name">{{ l.transliteration }}</span>
                            </button>
                        </div>

                        <div v-if="letter" class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-baseline mb-2">
                                    <h6 class="mb-0">{{ letter.arabic_name }} — {{ letter.transliteration }}</h6>
                                    <button class="btn-close" @click="openLetter = null"></button>
                                </div>

                                <!-- A word BEGINS at the right, so initial form sits on the right. -->
                                <div v-if="letter.positions?.length" class="d-flex gap-2 mb-3" dir="rtl">
                                    <div v-for="p in letter.positions" :key="p.id" class="shape-box">
                                        <div class="shape-box__glyph">{{ p.text }}</div>
                                        <div class="shape-box__label">{{ positionLabel(p.id) }}</div>
                                    </div>
                                </div>

                                <div class="list-group" dir="rtl">
                                    <button v-for="d in letter.drills" :key="d.id" type="button"
                                            class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                                            :class="`drill--${d.status}`" :disabled="marking === d.id"
                                            @click="advance(d)">
                                        <span class="drill__glyph">{{ d.text }}</span>
                                        <span class="flex-grow-1 small" dir="ltr" style="text-align:start;">
                                            {{ d.label }}
                                            <span v-if="d.sound" class="text-muted">· sounds like “{{ d.sound }}”</span>
                                        </span>
                                        <span class="badge" :class="badgeClass(d.status)">{{ statusLabel(d.status) }}</span>
                                    </button>
                                </div>
                                <p class="text-muted small mt-2 mb-0">Tap to move: Not started → Learning → Mastered.</p>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            <!-- ==================================================== POINTS -->
            <section v-else-if="activeTab === 'points'">
                <p class="text-muted small">Behaviour points for one student at a time.</p>
                <label class="form-label small text-muted">Student</label>
                <select class="form-select form-select-sm mb-3" style="max-width: 22rem"
                        v-model="pointsMembership" @change="loadAwards">
                    <option value="">Choose a student…</option>
                    <option v-for="s in students" :key="s.membership_id" :value="s.membership_id">
                        {{ name(s.contact) }}
                    </option>
                </select>

                <template v-if="pointsMembership">
                    <!-- Add points, when a behaviour vocabulary is available. -->
                    <div class="card border mb-3" v-if="skills.length">
                        <div class="card-body">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-sm">
                                    <label class="form-label small text-muted mb-1">Skill</label>
                                    <select class="form-select form-select-sm" v-model="awardSkillId">
                                        <option v-for="sk in skills" :key="sk.id" :value="sk.id">
                                            {{ sk.label }} ({{ sk.polarity === 'negative' ? '−' : '+' }}{{ Math.abs(sk.default_points ?? 1) }})
                                        </option>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Points</label>
                                    <input type="number" class="form-control form-control-sm" style="width:6rem"
                                           v-model.number="awardPoints" placeholder="default">
                                </div>
                                <div class="col-12 col-sm">
                                    <label class="form-label small text-muted mb-1">Note (optional)</label>
                                    <input type="text" class="form-control form-control-sm" v-model.trim="awardNote">
                                </div>
                                <div class="col-auto">
                                    <button class="btn btn-sm btn-success" :disabled="awarding || !awardSkillId" @click="giveAward">
                                        <span v-if="awarding" class="spinner-border spinner-border-sm"></span>
                                        <span v-else>Give</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="alert alert-light border small">
                        No behaviour skills are defined for this school yet, so points cannot be given here.
                        An administrator can add them.
                    </div>

                    <div v-if="awardError" class="alert alert-danger small py-2">{{ awardError }}</div>

                    <div v-if="awardsLoading" class="text-center py-3"><span class="spinner-border spinner-border-sm text-success"></span></div>
                    <p v-else-if="!awards.length" class="text-muted small">Nothing recorded yet.</p>
                    <ul v-else class="list-unstyled mb-0">
                        <li v-for="a in awards" :key="a.id" class="d-flex gap-2 align-items-baseline py-1 border-bottom">
                            <span class="badge"
                                  :class="a.polarity === 'negative' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success-emphasis'">
                                {{ a.points > 0 ? '+' : '' }}{{ a.points }}
                            </span>
                            <span class="small flex-grow-1">
                                {{ a.skill_label }}
                                <span class="text-muted">· {{ when(a.awarded_at) }}</span>
                                <span v-if="a.note" class="text-muted"> — {{ a.note }}</span>
                            </span>
                            <button class="btn btn-sm btn-link text-danger p-0" :disabled="removingAward === a.id"
                                    @click="removeAward(a)">Remove</button>
                        </li>
                    </ul>
                </template>
            </section>

            <!-- ==================================================== HIFZ -->
            <section v-else-if="activeTab === 'hifz'">
                <p class="text-muted small">Qur'an recitation log for one student at a time.</p>
                <label class="form-label small text-muted">Student</label>
                <select class="form-select form-select-sm mb-3" style="max-width: 22rem"
                        v-model="hifzMembership" @change="loadHifz">
                    <option value="">Choose a student…</option>
                    <option v-for="s in students" :key="s.membership_id" :value="s.membership_id">
                        {{ name(s.contact) }}
                    </option>
                </select>

                <template v-if="hifzMembership">
                    <div class="card border mb-3">
                        <div class="card-body">
                            <div class="row g-2 align-items-end">
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Kind</label>
                                    <select class="form-select form-select-sm" v-model="hifzForm.kind">
                                        <option value="sabak">Sabak (new)</option>
                                        <option value="sabqi">Sabqi (recent)</option>
                                        <option value="manzil">Manzil (old)</option>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">From surah:ayah</label>
                                    <div class="d-flex gap-1">
                                        <input type="number" min="1" class="form-control form-control-sm" style="width:4.5rem" v-model.number="hifzForm.from_surah" placeholder="S">
                                        <input type="number" min="1" class="form-control form-control-sm" style="width:4.5rem" v-model.number="hifzForm.from_ayah" placeholder="A">
                                    </div>
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">To surah:ayah</label>
                                    <div class="d-flex gap-1">
                                        <input type="number" min="1" class="form-control form-control-sm" style="width:4.5rem" v-model.number="hifzForm.to_surah" placeholder="S">
                                        <input type="number" min="1" class="form-control form-control-sm" style="width:4.5rem" v-model.number="hifzForm.to_ayah" placeholder="A">
                                    </div>
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Quality</label>
                                    <select class="form-select form-select-sm" v-model="hifzForm.quality">
                                        <option value="excellent">Excellent</option>
                                        <option value="good">Good</option>
                                        <option value="fair">Fair</option>
                                        <option value="needs_work">Needs work</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button class="btn btn-sm btn-success" :disabled="recordingHifz || !hifzValid" @click="recordHifz">
                                        <span v-if="recordingHifz" class="spinner-border spinner-border-sm"></span>
                                        <span v-else>Record</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="hifzError" class="alert alert-danger small py-2">{{ hifzError }}</div>

                    <div v-if="hifzLoading" class="text-center py-3"><span class="spinner-border spinner-border-sm text-success"></span></div>
                    <p v-else-if="!hifz.length" class="text-muted small">Nothing recorded yet.</p>
                    <ul v-else class="list-unstyled mb-0">
                        <li v-for="h in hifz" :key="h.id" class="d-flex gap-2 align-items-baseline py-1 border-bottom small">
                            <span class="text-capitalize flex-grow-1">
                                {{ h.kind }}: {{ ayah(h.from) }} &rarr; {{ ayah(h.to) }}
                                <span class="text-muted">· {{ h.quality }} · {{ when(h.recited_at) }}</span>
                            </span>
                            <button class="btn btn-sm btn-link text-danger p-0" :disabled="removingHifz === h.id"
                                    @click="removeHifz(h)">Remove</button>
                        </li>
                    </ul>
                </template>
            </section>

            <!-- ==================================================== STORY -->
            <section v-else-if="activeTab === 'story'">
                <div class="card border mb-4">
                    <div class="card-body">
                        <input type="text" class="form-control form-control-sm mb-2" placeholder="Title (optional)"
                               v-model.trim="composeTitle">
                        <textarea class="form-control mb-2" rows="3" placeholder="Share what happened today…"
                                  v-model.trim="composeBody"></textarea>
                        <div class="d-flex align-items-center gap-2">
                            <span v-if="postError" class="text-danger small">{{ postError }}</span>
                            <button class="btn btn-sm btn-success ms-auto" :disabled="posting || !composeBody" @click="submitPost">
                                <span v-if="posting" class="spinner-border spinner-border-sm me-1"></span>Post
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="postsLoading" class="text-center py-3"><span class="spinner-border text-success"></span></div>
                <div v-else-if="!posts.length" class="text-muted small">Nothing posted yet.</div>
                <div v-else class="d-flex flex-column gap-3">
                    <article v-for="post in posts" :key="post.id" class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <h2 v-if="post.title" class="h6 mb-1">{{ post.title }}</h2>
                                <button class="btn btn-sm btn-link text-danger p-0 ms-auto"
                                        :disabled="removingPost === post.id" @click="deletePost(post)">Remove</button>
                            </div>
                            <p class="text-muted small mb-2">
                                {{ post.author?.name || 'You' }} · {{ when(post.created_at) }}
                            </p>
                            <p class="mb-2" style="white-space: pre-wrap;">{{ post.body }}</p>
                            <div v-if="post.attachments?.length" class="text-muted small">
                                <i class="bi bi-paperclip"></i>
                                {{ post.attachments.length }} attachment{{ post.attachments.length === 1 ? '' : 's' }}
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <!-- ==================================================== MESSAGES -->
            <section v-else-if="activeTab === 'messages'">
                <p class="text-muted small">One-to-one conversations with guardians. You can reply to a thread.</p>

                <div v-if="threadsLoading" class="text-center py-3"><span class="spinner-border text-success"></span></div>
                <div v-else-if="!threads.length" class="text-muted small">No messages yet.</div>
                <div v-else class="d-flex flex-column gap-2">
                    <button v-for="thread in threads" :key="thread.id" type="button"
                            class="card border-0 shadow-sm text-start" @click="openThread(thread)">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-semibold small">
                                        {{ thread.subject || 'Message' }}
                                        <span v-if="thread.unread" class="badge bg-success ms-1">New</span>
                                    </div>
                                    <div class="text-muted small">
                                        <span v-if="thread.about">About {{ thread.about.name || name(thread.about) }} · </span>
                                        {{ thread.message_count }} message{{ thread.message_count === 1 ? '' : 's' }}
                                    </div>
                                </div>
                                <span class="text-muted small text-nowrap">{{ when(thread.latest_message_at || thread.created_at) }}</span>
                            </div>
                        </div>
                    </button>
                </div>

                <div v-if="openedThread" class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <strong class="small">{{ openedThread.subject || 'Message' }}</strong>
                        <button class="btn-close" @click="openedThread = null"></button>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                        <div v-for="m in openedMessages" :key="m.id"
                             :class="m.is_mine ? 'align-self-end text-end' : ''" style="max-width: 85%;">
                            <div class="text-muted small">
                                {{ m.is_mine ? 'You' : (m.author?.name || 'Guardian') }} · {{ when(m.created_at) }}
                            </div>
                            <div class="rounded px-3 py-2 d-inline-block text-start"
                                 :class="m.is_mine ? 'bg-success-subtle' : 'bg-light'"
                                 style="white-space: pre-wrap;">{{ m.body }}</div>
                        </div>
                    </div>
                    <div class="card-footer bg-white">
                        <div v-if="openedThread.is_closed" class="text-muted small">This conversation is closed.</div>
                        <template v-else>
                            <div v-if="replyError" class="alert alert-danger small py-2">{{ replyError }}</div>
                            <div class="d-flex gap-2 align-items-end">
                                <textarea v-model="replyBody" class="form-control" rows="2"
                                          placeholder="Write a reply…" @keydown.ctrl.enter="sendReply"></textarea>
                                <button class="btn btn-success" :disabled="!replyBody.trim() || sendingReply" @click="sendReply">
                                    <span v-if="sendingReply" class="spinner-border spinner-border-sm"></span>
                                    <span v-else>Send</span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </section>
        </template>

        <!-- Avatar picker modal (Roster tab) -->
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
                            :catalogue-endpoint="`/api/teacher/masjids/${masjidId}/avatars`"
                            :family-endpoint="`${base}/members/${avatarFor.membership_id}/avatar`"
                            @saved="onAvatarSaved" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import TeacherApiService, { rowsOf } from '@/core/services/TeacherApiService';
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import AvatarPicker from '@/components/common/AvatarPicker.vue';
import { useAuthStore } from '@/stores/authStore';
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

type TabKey = 'roster' | 'letters' | 'points' | 'hifz' | 'story' | 'messages';

const route = useRoute();
const authStore = useAuthStore();

const groupId = computed(() => String(route.params.groupId));
// The teacher realm addresses its API per-school, matching the family shape
// (/api/teacher/masjids/{masjid_id}/...). The id is the teacher's bound school,
// seeded into the auth store at login; the server IGNORES it for tenant binding
// (that comes from the token) and uses it only to shape the route.
const masjidId = computed(() => authStore.dashboardMasjidId ?? 0);
const base = computed(() => `/api/teacher/masjids/${masjidId.value}/groups/${groupId.value}`);

const group = ref<any>(null);
const loading = ref(true);
const error = ref('');
const activeTab = ref<TabKey>('roster');

const tabs: { key: TabKey; label: string; icon: string }[] = [
    { key: 'roster', label: 'Roster', icon: 'bi-people' },
    { key: 'letters', label: 'Letters', icon: 'bi-fonts' },
    { key: 'points', label: 'Points', icon: 'bi-star' },
    { key: 'hifz', label: 'Ḥifẓ', icon: 'bi-book' },
    { key: 'story', label: 'Class Story', icon: 'bi-journal-text' },
    { key: 'messages', label: 'Messages', icon: 'bi-chat-dots' },
];

const students = computed<any[]>(() => group.value?.students ?? []);
const avatarFor = ref<any>(null);

// ---------- helpers ----------
const name = (c: any) => [c?.first_name, c?.last_name].filter(Boolean).join(' ') || 'Student';

const guardianNames = (s: any): string => {
    // NAMES ONLY — never email/phone. The frozen Student shape carries no
    // guardians, so this renders only when the backend chooses to include them.
    const list = s?.guardians ?? s?.contact?.guardians ?? [];
    if (!Array.isArray(list) || !list.length) return '';
    return list
        .map((g: any) => g?.name || [g?.first_name, g?.last_name].filter(Boolean).join(' '))
        .filter(Boolean)
        .join(', ');
};

const when = (iso: string | null) => {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
};

const ayah = (ref: any) => {
    if (!ref) return '';
    if (typeof ref === 'string') return ref;
    return `${ref.surah_name ?? 'Surah ' + ref.surah} ${ref.ayah}`;
};

const loadGroup = async () => {
    loading.value = true;
    error.value = '';
    try {
        const res = await TeacherApiService.get(base.value);
        group.value = res.data?.data ?? null;
    } catch {
        error.value = 'We could not load this class just now. Please try again.';
    } finally {
        loading.value = false;
    }
};

onMounted(loadGroup);

// ============================================================ LETTERS
const selected = ref<any>(null);
const tracker = ref<any>(null);
const trackerLoading = ref(false);
const openLetter = ref<string | null>(null);
const marking = ref<string | null>(null);
const savingStage = ref(false);
const stageNote = ref('');

const letter = computed(() => tracker.value?.letters?.find((l: any) => l.id === openLetter.value) ?? null);

// Stage options come from whatever the letters payload exposes (the family/admin
// overview carries `stages`); the mutation itself uses the frozen PUT.
const stageOptions = computed<any[]>(() => tracker.value?.stages ?? group.value?.arabic_stages ?? []);
const currentStageId = computed(() => tracker.value?.stage?.id ?? group.value?.arabic_stage ?? '');
const currentStageLabel = computed(() => tracker.value?.stage?.label ?? group.value?.arabic_stage ?? '');

const POSITIONS: Record<string, string> = { isolated: 'Alone', initial: 'Beginning', medial: 'Middle', final: 'End' };
const positionLabel = (id: string) => POSITIONS[id] ?? id;
const STATUS: Record<string, string> = { not_started: 'Not started', learning: 'Learning', mastered: 'Mastered' };
const statusLabel = (s: string) => STATUS[s] ?? s;
const badgeClass = (s: string) => s === 'mastered'
    ? 'bg-success-subtle text-success-emphasis'
    : (s === 'learning' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light text-muted');
const NEXT: Record<string, string> = { not_started: 'learning', learning: 'mastered', mastered: 'not_started' };

const openLetters = async (s: any) => {
    selected.value = s;
    openLetter.value = null;
    tracker.value = null;
    trackerLoading.value = true;
    try {
        const res = await TeacherApiService.get(`${base.value}/members/${s.membership_id}/letters`);
        tracker.value = res.data?.data ?? null;
    } catch {
        tracker.value = null;
    } finally {
        trackerLoading.value = false;
    }
};

const advance = async (drill: any) => {
    if (!selected.value) return;
    marking.value = drill.id;
    try {
        const res = await TeacherApiService.put(
            `${base.value}/members/${selected.value.membership_id}/letters`,
            { drill_id: drill.id, status: NEXT[drill.status] ?? 'learning' }
        );
        // Marking returns the whole tracker, so totals and tile colour move together.
        tracker.value = res.data?.data ?? tracker.value;
    } catch {
        // Leave the tile as-is; a transient failure should not lie about progress.
    } finally {
        marking.value = null;
    }
};

const setStage = async (stage: string) => {
    savingStage.value = true;
    stageNote.value = '';
    try {
        const res = await TeacherApiService.put(`${base.value}/letters/stage`, { stage });
        stageNote.value = res.data?.message ?? 'Class stage updated.';
        if (group.value) group.value.arabic_stage = stage;
        // A narrower stage re-scopes an open tracker.
        if (selected.value) await openLetters(selected.value);
    } catch {
        stageNote.value = 'The class stage could not be changed.';
    } finally {
        savingStage.value = false;
    }
};

// ============================================================ POINTS
const pointsMembership = ref<string | number>('');
const awards = ref<any[]>([]);
const awardsLoading = ref(false);
const awardError = ref('');
const removingAward = ref<string | number | null>(null);
const skills = ref<any[]>([]);
const awardSkillId = ref<string | number>('');
const awardPoints = ref<number | null>(null);
const awardNote = ref('');
const awarding = ref(false);

const loadAwards = async () => {
    awards.value = [];
    if (!pointsMembership.value) return;
    awardsLoading.value = true;
    awardError.value = '';
    try {
        const res = await TeacherApiService.get(`${base.value}/members/${pointsMembership.value}/awards`);
        awards.value = rowsOf(res.data?.data);
        // The behaviour vocabulary, if the payload carries it alongside the log.
        const s = res.data?.data?.skills ?? group.value?.behavior_skills ?? [];
        if (Array.isArray(s) && s.length) {
            skills.value = s;
            if (!awardSkillId.value) awardSkillId.value = s[0].id;
        }
    } catch {
        awardError.value = 'The behaviour record could not be loaded.';
    } finally {
        awardsLoading.value = false;
    }
};

// The behaviour vocabulary for the "give points" dropdown. Masjid-scoped (not
// per class), so it is loaded from the teacher's school, once.
const loadSkills = async () => {
    if (skills.value.length) return;
    try {
        const res = await TeacherApiService.get(`/api/teacher/masjids/${masjidId.value}/behavior-skills`);
        const s = rowsOf(res.data?.data);
        if (s.length) {
            skills.value = s;
            if (!awardSkillId.value) awardSkillId.value = s[0].id;
        }
    } catch {
        // Falls back to whatever the awards payload carried.
    }
};

const giveAward = async () => {
    if (!pointsMembership.value || !awardSkillId.value) return;
    awarding.value = true;
    awardError.value = '';
    try {
        const body: Record<string, any> = {
            membership_id: pointsMembership.value,
            behavior_skill_id: awardSkillId.value,
        };
        if (awardPoints.value !== null && awardPoints.value !== undefined) body.points = awardPoints.value;
        if (awardNote.value) body.note = awardNote.value;
        await TeacherApiService.post(`${base.value}/awards`, body);
        awardPoints.value = null;
        awardNote.value = '';
        await loadAwards();
    } catch (e: any) {
        awardError.value = e?.response?.data?.message || 'Those points could not be given.';
    } finally {
        awarding.value = false;
    }
};

const removeAward = async (award: any) => {
    removingAward.value = award.id;
    try {
        await TeacherApiService.delete(`${base.value}/awards/${award.id}`);
        awards.value = awards.value.filter((a) => a.id !== award.id);
    } catch {
        awardError.value = 'That entry could not be removed.';
    } finally {
        removingAward.value = null;
    }
};

// ============================================================ HIFZ
const hifzMembership = ref<string | number>('');
const hifz = ref<any[]>([]);
const hifzLoading = ref(false);
const hifzError = ref('');
const removingHifz = ref<string | number | null>(null);
const recordingHifz = ref(false);
const hifzForm = ref({
    kind: 'sabak',
    from_surah: null as number | null,
    from_ayah: null as number | null,
    to_surah: null as number | null,
    to_ayah: null as number | null,
    quality: 'good',
});
const hifzValid = computed(() =>
    !!hifzForm.value.from_surah && !!hifzForm.value.from_ayah &&
    !!hifzForm.value.to_surah && !!hifzForm.value.to_ayah);

const loadHifz = async () => {
    hifz.value = [];
    if (!hifzMembership.value) return;
    hifzLoading.value = true;
    hifzError.value = '';
    try {
        const res = await TeacherApiService.get(`${base.value}/members/${hifzMembership.value}/hifz`);
        hifz.value = rowsOf(res.data?.data);
    } catch {
        hifzError.value = 'The recitation log could not be loaded.';
    } finally {
        hifzLoading.value = false;
    }
};

const recordHifz = async () => {
    if (!hifzMembership.value || !hifzValid.value) return;
    recordingHifz.value = true;
    hifzError.value = '';
    try {
        await TeacherApiService.post(`${base.value}/hifz`, {
            membership_id: hifzMembership.value,
            kind: hifzForm.value.kind,
            from_surah: hifzForm.value.from_surah,
            from_ayah: hifzForm.value.from_ayah,
            to_surah: hifzForm.value.to_surah,
            to_ayah: hifzForm.value.to_ayah,
            quality: hifzForm.value.quality,
            major_mistakes: 0,
            minor_mistakes: 0,
        });
        hifzForm.value.from_surah = hifzForm.value.from_ayah = null;
        hifzForm.value.to_surah = hifzForm.value.to_ayah = null;
        await loadHifz();
    } catch (e: any) {
        hifzError.value = e?.response?.data?.message || 'That recitation could not be recorded.';
    } finally {
        recordingHifz.value = false;
    }
};

const removeHifz = async (entry: any) => {
    removingHifz.value = entry.id;
    try {
        await TeacherApiService.delete(`${base.value}/hifz/${entry.id}`);
        hifz.value = hifz.value.filter((h) => h.id !== entry.id);
    } catch {
        hifzError.value = 'That entry could not be removed.';
    } finally {
        removingHifz.value = null;
    }
};

// ============================================================ STORY
const posts = ref<any[]>([]);
const postsLoading = ref(false);
const composeTitle = ref('');
const composeBody = ref('');
const posting = ref(false);
const postError = ref('');
const removingPost = ref<string | number | null>(null);

const loadPosts = async () => {
    postsLoading.value = true;
    try {
        const res = await TeacherApiService.get(`${base.value}/posts`);
        posts.value = rowsOf(res.data?.data);
    } catch {
        posts.value = [];
    } finally {
        postsLoading.value = false;
    }
};

const submitPost = async () => {
    if (!composeBody.value) return;
    posting.value = true;
    postError.value = '';
    try {
        await TeacherApiService.post(`${base.value}/posts`, {
            title: composeTitle.value || null,
            body: composeBody.value,
        });
        composeTitle.value = '';
        composeBody.value = '';
        await loadPosts();
    } catch (e: any) {
        postError.value = e?.response?.data?.message || 'The post could not be published.';
    } finally {
        posting.value = false;
    }
};

const deletePost = async (post: any) => {
    removingPost.value = post.id;
    try {
        await TeacherApiService.delete(`${base.value}/posts/${post.id}`);
        posts.value = posts.value.filter((p) => p.id !== post.id);
    } catch {
        postError.value = 'That post could not be removed.';
    } finally {
        removingPost.value = null;
    }
};

// ============================================================ MESSAGES
const threads = ref<any[]>([]);
const threadsLoading = ref(false);
const openedThread = ref<any>(null);
const openedMessages = ref<any[]>([]);
const replyBody = ref('');
const sendingReply = ref(false);
const replyError = ref('');

const loadThreads = async () => {
    threadsLoading.value = true;
    try {
        const res = await TeacherApiService.get(`${base.value}/threads`);
        threads.value = rowsOf(res.data?.data);
    } catch {
        threads.value = [];
    } finally {
        threadsLoading.value = false;
    }
};

const openThread = async (thread: any) => {
    try {
        const res = await TeacherApiService.get(`${base.value}/threads/${thread.id}`);
        openedThread.value = res.data?.data?.thread ?? thread;
        replyBody.value = '';
        replyError.value = '';
        openedMessages.value = rowsOf(res.data?.data?.messages);
    } catch {
        replyError.value = 'That conversation could not be opened.';
    }
};

const sendReply = async () => {
    const body = replyBody.value.trim();
    if (!body || !openedThread.value) return;
    sendingReply.value = true;
    replyError.value = '';
    try {
        const res = await TeacherApiService.post(
            `${base.value}/threads/${openedThread.value.id}/messages`, { body }
        );
        openedMessages.value.push(res.data?.data);
        replyBody.value = '';
        await loadThreads();
    } catch (e: any) {
        replyError.value = e?.response?.data?.message || 'Your reply could not be sent.';
    } finally {
        sendingReply.value = false;
    }
};

// ---------- avatar save ----------
const onAvatarSaved = (student: any) => {
    if (avatarFor.value && student?.contact) {
        avatarFor.value.contact.avatar = student.contact.avatar ?? null;
    }
    avatarFor.value = null;
};

// ---------- lazy per-tab loading ----------
watch(activeTab, (tab) => {
    if (tab === 'story' && !posts.value.length && !postsLoading.value) loadPosts();
    if (tab === 'messages' && !threads.value.length && !threadsLoading.value) loadThreads();
    if (tab === 'points' && !skills.value.length) loadSkills();
    // Reset any open per-student detail when leaving a grading tab.
    if (tab !== 'letters') { selected.value = null; }
    if (tab !== 'messages') { openedThread.value = null; }
});
</script>

<style scoped>
.nav-tabs .nav-link { color: #6c757d; }
.nav-tabs .nav-link.active { color: #198754; font-weight: 600; }

.letter-tile {
    width: 66px; height: 66px; border: none; border-radius: 12px;
    background: var(--bs-tertiary-bg, #eceff1);
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
}
.letter-tile--learning { background: rgba(255, 193, 7, .25); }
.letter-tile--mastered { background: rgba(25, 135, 84, .24); }
.letter-tile__glyph { font-size: 24px; line-height: 1; }
.letter-tile__name { font-size: 10px; color: #6c757d; }
.shape-box { flex: 1; text-align: center; background: var(--bs-tertiary-bg, #eceff1); border-radius: 10px; padding: 8px 4px; }
.shape-box__glyph { font-size: 26px; line-height: 1.2; }
.shape-box__label { font-size: 10px; color: #6c757d; }
.drill__glyph { font-size: 26px; width: 52px; text-align: center; }
.drill--mastered { background: rgba(25, 135, 84, .10); }
.drill--learning { background: rgba(255, 193, 7, .10); }
</style>
