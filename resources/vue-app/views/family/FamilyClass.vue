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
                <li v-if="group.children?.length" class="nav-item">
                    <button class="nav-link" :class="{ active: tab === 'reports' }" @click="tab = 'reports'">
                        Report cards
                    </button>
                </li>
                <!-- "Handouts", not "Resources" — that is the word a parent uses. -->
                <li class="nav-item">
                    <button class="nav-link" :class="{ active: tab === 'handouts' }" @click="tab = 'handouts'">
                        Handouts
                    </button>
                </li>
            </ul>

            <!-- -------------------------------------------------- handouts -->
            <section v-if="tab === 'handouts'">
                <p v-if="handoutsError" class="text-danger small">{{ handoutsError }}</p>
                <p v-else-if="!handouts.length" class="text-muted small mb-0">
                    Nothing shared yet. Anything your teacher sends home will appear here.
                </p>
                <div v-else class="list-group">
                    <div v-for="h in handouts" :key="h.id"
                         class="list-group-item d-flex align-items-center gap-3">
                        <i class="bi bi-file-earmark fs-5 text-muted"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small">{{ h.title }}</div>
                            <div class="text-muted small">
                                {{ h.original_name }} · {{ Math.max(1, Math.round(h.size_bytes / 1024)) }} KB
                            </div>
                            <div v-if="h.description" class="text-muted small">{{ h.description }}</div>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" @click="downloadHandout(h)">
                            <i class="bi bi-download"></i>
                        </button>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------------ class story -->
            <section v-else-if="tab === 'story'">
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
                <div v-if="!openedThread && group.children?.length" class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <button v-if="!composing" class="btn btn-sm btn-success" @click="startCompose">
                            <i class="bi bi-pencil-square me-1"></i>Message the teacher
                        </button>

                        <template v-else>
                            <div class="row g-2">
                                <div v-if="group.children.length > 1" class="col-12 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">About</label>
                                    <select class="form-select form-select-sm" v-model="composeForm.about_membership_id">
                                        <option v-for="c in group.children" :key="c.membership_id" :value="c.membership_id">
                                            {{ childName(c) }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-12 col-sm">
                                    <label class="form-label small text-muted mb-1">Subject</label>
                                    <input v-model="composeForm.subject" type="text" maxlength="255"
                                           class="form-control form-control-sm" placeholder="What is this about?">
                                </div>
                            </div>

                            <textarea v-model="composeForm.body" rows="3" maxlength="5000"
                                      class="form-control form-control-sm mt-2"
                                      placeholder="Your message to the teacher…"></textarea>

                            <p class="text-muted small mt-2 mb-2">
                                Only your child's teacher will see this.
                            </p>

                            <div class="d-flex align-items-center gap-2">
                                <button class="btn btn-sm btn-success"
                                        :disabled="sendingCompose || !composeForm.subject.trim() || !composeForm.body.trim()"
                                        @click="createThread">
                                    {{ sendingCompose ? 'Sending…' : 'Send' }}
                                </button>
                                <button class="btn btn-sm btn-link text-muted" @click="composing = false">Cancel</button>
                                <span v-if="composeError" class="text-danger small">{{ composeError }}</span>
                            </div>
                        </template>
                    </div>
                </div>

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
            <section v-else-if="tab === 'children'">
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
                            <!-- RTL, like the alphabet itself. -->
                            <div class="d-flex flex-wrap gap-1 mt-2" dir="rtl">
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

            <!-- ---------------------------------------------- report cards -->
            <!-- A report card is a document a family KEEPS, so this reads as a
                 document and not as a dashboard: no averages, no progress bars,
                 no red/amber/green. App\Support\PerformanceLevel argues at
                 length that a standards scale shown as a percentage or a
                 pass/fail is a misreading of it — a 2 is "Approaching
                 Expectations", which is a description of where a child is, and
                 painting it red says something the teacher did not say. The
                 teacher's own screen has no colour scale either; inventing one
                 here would be diverging from it, not matching it. -->
            <section v-else-if="tab === 'reports'">
                <p v-if="reportsError" class="text-danger small">{{ reportsError }}</p>

                <!-- ------------------------------------------ one open report -->
                <template v-if="openCard">
                    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none"
                                @click="openCard = null">
                            &larr; All reports
                        </button>
                        <!-- The printable copy. A report card is a document a
                             family keeps, and "keeps" for most people means a
                             file or a sheet of paper, not a tab. -->
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                :disabled="downloadingCard"
                                @click="downloadCard">
                            {{ downloadingCard ? 'Preparing…' : 'Download PDF' }}
                        </button>
                    </div>

                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="fw-semibold">{{ childName(openCardFor) }}</span>
                        <span v-if="openCard.grade_label"
                              class="badge bg-primary-subtle text-primary-emphasis fw-normal">
                            {{ openCard.grade_label }}
                        </span>
                    </div>
                    <div class="text-muted small mb-3">
                        {{ openCard.type_label }} &middot; {{ openCard.period_label }}
                        <span v-if="openCard.published_at">&middot; Sent {{ when(openCard.published_at) }}</span>
                    </div>

                    <!-- THE KEY, from the payload and never hardcoded. Open by
                         default, unlike the teacher's collapsed copy: a teacher
                         knows the scale by heart, a parent is meeting it for the
                         first time, and "what does a 3 mean?" has to be
                         answerable without emailing the school. Full labels
                         here, not the teacher's abbreviations. -->
                    <details v-if="levelKey.length" class="mb-3" open>
                        <summary class="small text-primary" style="cursor:pointer">
                            What do 4, 3, 2 and 1 mean?
                        </summary>
                        <dl class="row small mt-2 mb-0">
                            <template v-for="l in levelKey" :key="l.level">
                                <dt class="col-sm-4 fw-semibold">{{ l.level }} &mdash; {{ l.label }}</dt>
                                <dd class="col-sm-8 text-muted">{{ l.description }}</dd>
                            </template>
                        </dl>
                    </details>

                    <!-- The attendance FROZEN at publication, not recomputed.
                         `present` already includes the late days, so it says so
                         rather than leaving a parent to work out whether the
                         three figures are meant to add up. -->
                    <div v-if="hasAttendance" class="card border-0 bg-light mb-3">
                        <div class="card-body py-2 small">
                            Present {{ openCard.attendance.present }}
                            <span class="text-muted">(includes {{ openCard.attendance.late }} late)</span>
                            &middot; Absent {{ openCard.attendance.absent }}
                        </div>
                    </div>

                    <div v-for="sub in openCard.subjects" :key="sub.subject" class="card border-0 shadow-sm mb-2">
                        <div class="card-header bg-white fw-semibold small">{{ sub.subject }}</div>
                        <div class="list-group list-group-flush">
                            <!-- Keyed by subject+criterion, NOT by id. The family
                                 payload deliberately carries no mark ids, so a
                                 `:key="m.id"` copied from the teacher's template
                                 would collapse every row onto one undefined key. -->
                            <div v-for="(m, i) in sub.criteria" :key="`${sub.subject}-${m.criterion}-${i}`"
                                 class="list-group-item d-flex align-items-start gap-3 flex-wrap">
                                <div class="flex-grow-1" style="min-width:12rem">
                                    <span class="small">{{ m.criterion }}</span>
                                    <!-- The per-criterion note. A screen that shows
                                         only the card-level comment silently drops
                                         most of what a teacher actually wrote. -->
                                    <div v-if="m.comment" class="text-muted small fst-italic mt-1">{{ m.comment }}</div>
                                </div>
                                <span class="badge fw-normal flex-shrink-0" :class="levelClass(m)">
                                    {{ levelText(m) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- LEARNING BEHAVIOURS, deliberately their own card BELOW the
                         subjects, exactly as the teacher sees them: they are how a
                         child works, not what a child knows, and folding them in
                         beside a subject is what that separation exists to stop. -->
                    <div v-if="openCard.learning_behaviours?.length" class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold small">Learning behaviours</div>
                        <div class="list-group list-group-flush">
                            <div v-for="(m, i) in openCard.learning_behaviours" :key="`behaviour-${m.criterion}-${i}`"
                                 class="list-group-item d-flex align-items-start gap-3 flex-wrap">
                                <div class="flex-grow-1" style="min-width:12rem">
                                    <span class="small">{{ m.criterion }}</span>
                                    <div v-if="m.comment" class="text-muted small fst-italic mt-1">{{ m.comment }}</div>
                                </div>
                                <span class="badge fw-normal flex-shrink-0" :class="levelClass(m)">
                                    {{ levelText(m) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div v-if="openCard.teacher_comment" class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="small text-muted mb-1">Comment from the teacher</div>
                            <p class="mb-0 small report-comment">{{ openCard.teacher_comment }}</p>
                        </div>
                    </div>
                </template>

                <!-- ---------------------------------- the reports, per child -->
                <template v-else>
                    <div v-if="reportsLoading && !reportsLoaded" class="text-center py-4">
                        <span class="spinner-border spinner-border-sm text-success"></span>
                    </div>

                    <template v-else>
                        <div v-for="child in group.children" :key="child.membership_id"
                             class="card border-0 shadow-sm mb-3">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <PersonAvatar
                                        :avatar="child.contact?.avatar"
                                        :first-name="child.contact?.first_name"
                                        :last-name="child.contact?.last_name"
                                        :size="42" />
                                    <h2 class="h6 mb-0">{{ childName(child) }}</h2>
                                </div>

                                <!-- "sent home", never "none published yet". A draft
                                     is a 404 to this realm by design, so the screen
                                     cannot say a report exists but is being withheld
                                     — and must not imply it either. -->
                                <p v-if="!reportCards[child.membership_id]?.length" class="text-muted small mb-0">
                                    No reports have been sent home yet. When one is, it will appear here.
                                </p>

                                <div v-else class="list-group list-group-flush">
                                    <button v-for="row in reportCards[child.membership_id]" :key="row.id"
                                            type="button"
                                            class="list-group-item list-group-item-action d-flex align-items-center gap-3 px-0"
                                            :disabled="openingCard === row.id"
                                            @click="openReportCard(child, row)">
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold small">{{ row.type_label }}</div>
                                            <div class="text-muted small">{{ row.period_label }}</div>
                                        </div>
                                        <span class="badge bg-success-subtle text-success-emphasis fw-normal">
                                            Sent {{ when(row.published_at) }}
                                        </span>
                                        <i class="bi bi-chevron-right text-muted"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>
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
                            :http="FamilyApiService"
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
const tab = ref<'story' | 'messages' | 'children' | 'reports' | 'handouts'>('story');

// ---------- starting a conversation ----------
const composing = ref(false);
const sendingCompose = ref(false);
const composeError = ref('');
const composeForm = ref<{ about_membership_id: number | null; subject: string; body: string }>({
    about_membership_id: null, subject: '', body: '',
});

const startCompose = () => {
    // Defaults to the only child when there is one, so the commonest case needs
    // no choice at all. `scope` is NEVER sent — the server forces participant.
    composeForm.value = {
        about_membership_id: group.value?.children?.[0]?.membership_id ?? null,
        subject: '', body: '',
    };
    composeError.value = '';
    composing.value = true;
};

const createThread = async () => {
    sendingCompose.value = true;
    composeError.value = '';
    try {
        await FamilyApiService.post(`${base.value}/threads`, {
            subject: composeForm.value.subject,
            about_membership_id: composeForm.value.about_membership_id,
            body: composeForm.value.body,
        });
        composing.value = false;
        // There is no standalone thread loader in this view — the list is
        // refreshed by re-reading the endpoint the class load uses.
        const t = await FamilyApiService.get(`${base.value}/threads`);
        threads.value = rowsOf(t.data?.data);
    } catch (e: any) {
        composeError.value = e?.response?.status === 429
            ? 'You have started several conversations already. Please continue one of them.'
            : (e?.response?.data?.message
                ?? e?.response?.data?.data?.subject?.[0]
                ?? 'That message could not be sent.');
    } finally {
        sendingCompose.value = false;
    }
};

// Handouts the class chose to share. The API applies visibility as a SCOPE, so
// a staff-only file is never in this list and its name is never in this payload.
const handouts = ref<any[]>([]);
const handoutsError = ref('');

const loadHandouts = async () => {
    handoutsError.value = '';
    try {
        const res = await FamilyApiService.get(`${base.value}/resources`);
        handouts.value = res.data?.data ?? [];
    } catch {
        handoutsError.value = 'These could not be loaded just now.';
    }
};

const downloadHandout = async (h: any) => {
    try {
        const url = await FamilyApiService.blobUrl(`${base.value}/resources/${h.id}/download`);
        const a = document.createElement('a');
        a.href = url;
        a.download = h.original_name;
        a.click();
        URL.revokeObjectURL(url);
    } catch {
        handoutsError.value = 'That file could not be downloaded.';
    }
};

watch(tab, (t) => {
    if (t === 'handouts' && !handouts.value.length) loadHandouts();
    // Guarded on `reportsLoaded`, not on an empty list: having no reports yet is
    // the ordinary state for most of a school year, so a length check would
    // refetch on every visit to the tab. `reportsLoading` stops a double-tap
    // firing two rounds of requests.
    if (t === 'reports' && !reportsLoaded.value && !reportsLoading.value) loadReportCards();
});
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

// ---------- report cards ----------
// The list and the document are two requests: the index carries no marks (and
// no level key), because a parent with three children should not pay for three
// full report cards to find out whether any exist.
const reportCards = ref<Record<number, any[]>>({});
const reportsLoaded = ref(false);
const reportsLoading = ref(false);
const reportsError = ref('');
const levelKey = ref<any[]>([]);
const openCard = ref<any>(null);
const openCardFor = ref<any>(null);
const openingCard = ref<number | null>(null);

const loadReportCards = async () => {
    reportsLoading.value = true;
    reportsError.value = '';

    try {
        for (const child of group.value?.children ?? []) {
            try {
                const res = await FamilyApiService.get(
                    `${base.value}/members/${child.membership_id}/report-cards`,
                );
                reportCards.value[child.membership_id] = rowsOf(res.data?.data);
            } catch (e) {
                if (fail(e)) return;
                // Per child, so one sibling's failure does not blank the other's
                // reports — the same reason loadChildRecords() catches inside
                // its loop rather than around it.
                reportCards.value[child.membership_id] = [];
                reportsError.value = 'Some reports could not be loaded just now.';
            }
        }

        reportsLoaded.value = true;
    } finally {
        reportsLoading.value = false;
    }
};

/** Open one report in full. */
const openReportCard = async (child: any, row: any) => {
    openingCard.value = row.id;
    reportsError.value = '';

    try {
        const res = await FamilyApiService.get(
            `${base.value}/members/${child.membership_id}/report-cards/${row.id}`,
        );
        openCard.value = res.data?.data ?? null;
        openCardFor.value = child;
        // `performance_levels` is a SIBLING of `data`, not a member of it. The
        // `?? existing` keeps an already-loaded key rather than blanking the
        // legend if a response ever arrives without one.
        levelKey.value = res.data?.performance_levels ?? levelKey.value;
    } catch (e) {
        if (!fail(e)) reportsError.value = 'That report could not be opened.';
    } finally {
        openingCard.value = null;
    }
};

const downloadingCard = ref(false);

/**
 * Fetch the PDF as a blob rather than pointing the browser at the URL.
 *
 * The route is bearer-authenticated like every other call in this realm, and a
 * plain link carries no Authorization header — it would arrive unauthenticated
 * and 401. Same reason, and the same helper, as the handout download above.
 */
const downloadCard = async () => {
    if (!openCard.value || !openCardFor.value) return;

    downloadingCard.value = true;

    try {
        const url = await FamilyApiService.blobUrl(
            `${base.value}/members/${openCardFor.value.membership_id}/report-cards/${openCard.value.id}/pdf`,
        );
        const a = document.createElement('a');
        a.href = url;
        a.download = `${childName(openCardFor.value)} - ${openCard.value.period_label}.pdf`;
        a.click();
        URL.revokeObjectURL(url);
    } catch (e) {
        if (!fail(e)) reportsError.value = 'That report could not be downloaded just now.';
    } finally {
        downloadingCard.value = false;
    }
};

/**
 * NULL is "not assessed" — a true thing to say about a child who joined in week
 * eight — and is never a zero, never an empty pill, and never folded into an
 * average. Both the model and both controllers state this rule explicitly.
 */
const levelText = (m: any): string => {
    if (m?.level === null || m?.level === undefined) return 'Not assessed';

    const short = levelKey.value.find((l: any) => l.level === m.level)?.short_label;

    return `${m.level} · ${short ?? m.level_label ?? ''}`.trim();
};

const levelClass = (m: any): string =>
    m?.level === null || m?.level === undefined
        ? 'bg-light text-muted'
        : 'bg-primary-subtle text-primary-emphasis';

/** Null is "no figure recorded", which is not the same as a zero. */
const hasAttendance = computed(() =>
    openCard.value?.attendance != null && openCard.value.attendance.present !== null,
);

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

watch(tab, () => {
    openedThread.value = null;
    openCard.value = null;
});
</script>

<style scoped>
.letter-chip {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 8px; font-size: 17px; line-height: 1;
    background: var(--bs-tertiary-bg, #eceff1); color: #444;
}
.letter-chip--learning { background: rgba(255, 193, 7, .28); }
.letter-chip--mastered { background: rgba(25, 135, 84, .26); color: #0f5132; }

/* A teacher's comment is 4000 characters of free text, and where they put the
   line breaks is part of what they wrote. */
.report-comment { white-space: pre-wrap; }
</style>

