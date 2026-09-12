<template>
    <div :dir="dir" :lang="lang">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <!-- An arrow is a direction. "Back" points at the start of the line,
                 which is the left in English and the right in Arabic, so the
                 glyph is chosen rather than hardcoded as &larr;. -->
            <router-link :to="`/family/${masjidId}`"
                         class="text-decoration-none small d-inline-flex align-items-center gap-1">
                <i :class="backIcon"></i>{{ t('class_all') }}
            </router-link>

            <div class="d-flex align-items-center gap-2">
                <!-- TRANSLATE WHAT THE SCHOOL WROTE — one control, for the whole
                     screen, labelled in both languages at once.
                     A parent who cannot read the page cannot read a button on
                     it either, and this is the one button that has to be found
                     by exactly that parent. It sits beside the chrome toggle
                     because the two are the same kind of decision, and it is
                     hidden while there is nothing on this tab to translate
                     rather than offered as a control that would do nothing.
                     `hasTranslated` keeps it once anything has been translated:
                     the same button is the way back to the school's own words,
                     and a tab with no prose of its own must not strand a parent
                     in a translation they cannot turn off. -->
                <button v-if="translationAvailable && (translatableItems.length || hasTranslated)" type="button"
                        class="btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1"
                        :disabled="translating" :aria-busy="translating" @click="onTranslate">
                    <span v-if="translating" class="spinner-border spinner-border-sm"></span>
                    <i v-else class="bi bi-translate"></i>
                    {{ translationShowing ? tBoth('tr_show_original') : tBoth('tr_translate') }}
                </button>

                <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0"
                        :title="t('switch_lang_title')" @click="toggle">
                    {{ switchLabel }}
                </button>
            </div>
        </div>

        <!-- What the translation actually did, in both languages for the same
             reason the button is. The screen must never let a parent believe
             they have read the whole of what the school sent them when part of
             it is still in a language they do not read, so an incomplete run
             says so — and a complete one still says the words are a machine's
             rendering and where the school's own are. -->
        <div v-if="translationError" class="alert alert-warning py-2 small">
            {{ tMessageBoth(translationError) }}
        </div>
        <div v-else-if="translationShowing && translationIncomplete" class="alert alert-warning py-2 small">
            {{ tBoth('tr_incomplete') }}
        </div>
        <p v-if="translationShowing" class="text-muted small d-flex align-items-baseline gap-2">
            <i class="bi bi-translate"></i><span>{{ tBoth('tr_machine') }}</span>
        </p>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border text-success"></span></div>

        <div v-else-if="error" class="alert alert-danger">{{ tMessage(error) }}</div>

        <template v-else-if="group">
            <!-- dir="auto" on everything the school wrote, here and below. A
                 class named in Arabic inside an English portal — or the reverse,
                 which is the common case at this school — is a run in the other
                 direction, and letting the browser resolve it per string is the
                 only thing that renders both correctly. It changes direction
                 only; the words stay exactly as the school typed them. -->
            <h1 class="h4 mb-1" dir="auto">{{ group.name }}</h1>
            <p v-if="group.description" class="text-muted small mb-3" dir="auto">{{ txGroupDescription() }}</p>

            <ul class="nav nav-pills gap-1 mb-4">
                <li class="nav-item">
                    <button class="nav-link" :class="{ active: tab === 'story' }" @click="tab = 'story'">
                        {{ t('tab_story') }}
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" :class="{ active: tab === 'messages' }" @click="tab = 'messages'">
                        {{ t('tab_messages') }}
                    </button>
                </li>
                <li v-if="group.children?.length" class="nav-item">
                    <button class="nav-link" :class="{ active: tab === 'children' }" @click="tab = 'children'">
                        {{ group.children.length === 1 ? childName(group.children[0]) : t('tab_children') }}
                    </button>
                </li>
                <li v-if="group.children?.length" class="nav-item">
                    <button class="nav-link" :class="{ active: tab === 'reports' }" @click="tab = 'reports'">
                        {{ t('tab_reports') }}
                    </button>
                </li>
                <!-- ALWAYS shown to a parent with a child in this class, even
                     before anything is loaded and even when nothing is marked.
                     A parent arriving from the "a mark has been posted" email
                     has been told there is something to read, and a tab that
                     hid itself until it had content would leave that parent
                     hunting for a screen the school just told them exists.
                     This deliberately does NOT copy the letters section's
                     hide-when-empty behaviour, which solves the opposite
                     problem — a track the school never teaches. -->
                <li v-if="group.children?.length" class="nav-item">
                    <button class="nav-link" :class="{ active: tab === 'grades' }" @click="tab = 'grades'">
                        {{ t('tab_grades') }}
                    </button>
                </li>
                <!-- "Handouts", not "Resources" — that is the word a parent uses. -->
                <li class="nav-item">
                    <button class="nav-link" :class="{ active: tab === 'handouts' }" @click="tab = 'handouts'">
                        {{ t('tab_handouts') }}
                    </button>
                </li>
            </ul>

            <!-- -------------------------------------------------- handouts -->
            <section v-if="tab === 'handouts'">
                <p v-if="handoutsError" class="text-danger small">{{ tMessage(handoutsError) }}</p>
                <p v-else-if="!handouts.length" class="text-muted small mb-0">{{ t('handouts_empty') }}</p>
                <div v-else class="list-group">
                    <div v-for="h in handouts" :key="h.id"
                         class="list-group-item d-flex align-items-center gap-3">
                        <i class="bi bi-file-earmark fs-5 text-muted"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small" dir="auto">{{ txHandout(h, 'title') }}</div>
                            <div class="text-muted small">
                                <!-- A filename is one Latin run: unfenced, bidi
                                     reordering drags the extension across the
                                     dot separator in an Arabic line. -->
                                <bdi>{{ h.original_name }}</bdi> ·
                                {{ Math.max(1, Math.round(h.size_bytes / 1024)) }} {{ t('kb') }}
                            </div>
                            <div v-if="h.description" class="text-muted small" dir="auto">{{ txHandout(h, 'description') }}</div>
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
                    {{ t('story_no_consent') }}
                </div>

                <div v-else-if="!posts.length" class="text-muted small">{{ t('story_empty') }}</div>

                <div v-else class="d-flex flex-column gap-3">
                    <article v-for="post in posts" :key="post.id" class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h2 v-if="post.title" class="h6 mb-1" dir="auto">{{ txPost(post, 'title') }}</h2>
                            <p class="text-muted small mb-2">
                                {{ post.author?.name || t('the_school') }} · {{ when(post.created_at) }}
                            </p>
                            <p class="mb-2" style="white-space: pre-wrap;" dir="auto">{{ txPost(post, 'body') }}</p>

                            <div v-if="post.attachments?.length" class="d-flex flex-wrap gap-2">
                                <FamilyAttachment v-for="a in post.attachments" :key="a.id"
                                                  :src="attachmentUrl(post.id, a.id)" :name="a.name" />
                            </div>

                            <!-- The API says so explicitly rather than serving a shorter list. -->
                            <p v-if="post.media_withheld" class="text-muted small fst-italic mb-0 mt-2">
                                {{ t('media_withheld') }}
                            </p>
                        </div>
                    </article>
                </div>
            </section>

            <!-- -------------------------------------------------- messages -->
            <section v-else-if="tab === 'messages'">
                <div v-if="!openedThread && group.children?.length" class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <!-- `gap-1` rather than the icon's old `me-1`: a margin-end
                             utility is a left margin in this LTR-only Bootstrap
                             build, so it would sit on the wrong side of the
                             label in Arabic. A flex gap has no side. -->
                        <button v-if="!composing" class="btn btn-sm btn-success d-inline-flex align-items-center gap-1"
                                @click="startCompose">
                            <i class="bi bi-pencil-square"></i>{{ t('msg_teacher') }}
                        </button>

                        <template v-else>
                            <div class="row g-2">
                                <div v-if="group.children.length > 1" class="col-12 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">{{ t('compose_about') }}</label>
                                    <select class="form-select form-select-sm" v-model="composeForm.about_membership_id">
                                        <option v-for="c in group.children" :key="c.membership_id" :value="c.membership_id">
                                            {{ childName(c) }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-12 col-sm">
                                    <label class="form-label small text-muted mb-1">{{ t('compose_subject') }}</label>
                                    <input v-model="composeForm.subject" type="text" maxlength="255"
                                           class="form-control form-control-sm" :placeholder="t('compose_subject_ph')">
                                </div>
                            </div>

                            <textarea v-model="composeForm.body" rows="3" maxlength="5000"
                                      class="form-control form-control-sm mt-2"
                                      :placeholder="t('compose_body_ph')"></textarea>

                            <p class="text-muted small mt-2 mb-2">{{ t('compose_private') }}</p>

                            <div class="d-flex align-items-center gap-2">
                                <button class="btn btn-sm btn-success"
                                        :disabled="sendingCompose || !composeForm.subject.trim() || !composeForm.body.trim()"
                                        @click="createThread">
                                    {{ sendingCompose ? t('sending') : t('send') }}
                                </button>
                                <button class="btn btn-sm btn-link text-muted" @click="composing = false">
                                    {{ t('cancel') }}
                                </button>
                                <span v-if="composeError" class="text-danger small">{{ tMessage(composeError) }}</span>
                            </div>
                        </template>
                    </div>
                </div>

                <div v-if="!threads.length" class="text-muted small">{{ t('threads_empty') }}</div>

                <div v-else class="d-flex flex-column gap-2">
                    <button v-for="thread in threads" :key="thread.id" type="button"
                            class="card border-0 shadow-sm align-start"
                            @click="openThread(thread)">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-semibold small" dir="auto">
                                        {{ txThreadSubject(thread) || t('thread_untitled') }}
                                        <span v-if="thread.unread" class="badge bg-success badge-after">{{ t('thread_new') }}</span>
                                    </div>
                                    <div class="text-muted small">
                                        <span v-if="thread.about">
                                            {{ t('thread_about', thread.about.name || childName(thread.about)) }} ·
                                        </span>
                                        {{ tCount('msgs', thread.message_count) }}
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
                        <strong class="small" dir="auto">{{ txThreadSubject(openedThread) || t('thread_untitled') }}</strong>
                        <button class="btn-close" @click="openedThread = null"></button>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                        <!-- `align-self-end` is a cross-axis end in a column flex
                             box, so a parent's own messages move to the left in
                             Arabic with no override — which is where a reader of
                             Arabic expects their own side of a conversation. -->
                        <div v-for="m in openedMessages" :key="m.id"
                             :class="m.is_mine ? 'align-self-end align-end' : ''" style="max-width: 85%;">
                            <div class="text-muted small">
                                {{ m.is_mine ? t('msg_you') : (m.author?.name || t('the_school')) }} · {{ when(m.created_at) }}
                            </div>
                            <div class="rounded px-3 py-2 d-inline-block align-start"
                                 :class="m.is_mine ? 'bg-success-subtle' : 'bg-light'"
                                 style="white-space: pre-wrap;" dir="auto">{{ txMessageBody(m) }}</div>
                        </div>
                    </div>

                    <div class="card-footer bg-white">
                        <div v-if="openedThread.is_closed" class="text-muted small">{{ t('thread_closed') }}</div>
                        <template v-else>
                            <div v-if="replyError" class="alert alert-danger small py-2">{{ tMessage(replyError) }}</div>
                            <div class="d-flex gap-2 align-items-end">
                                <textarea v-model="replyBody" class="form-control" rows="2"
                                          :placeholder="t('reply_ph')" @keydown.ctrl.enter="sendReply"></textarea>
                                <button class="btn btn-success" :disabled="!replyBody.trim() || sendingReply"
                                        @click="sendReply">
                                    <span v-if="sendingReply" class="spinner-border spinner-border-sm"></span>
                                    <span v-else>{{ t('send') }}</span>
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
                                <h2 class="h6 mb-0" dir="auto">{{ childName(child) }}</h2>
                                <div class="d-flex gap-3">
                                    <button class="btn btn-link btn-sm p-0 text-decoration-none"
                                            @click="avatarFor = child">
                                        {{ t('choose_avatar') }}
                                    </button>
                                    <!-- Hand the phone over. The child gets a session
                                         of their own that cannot reach this portal. -->
                                    <button class="btn btn-link btn-sm p-0 text-decoration-none"
                                            :disabled="handingOver === child.membership_id"
                                            @click="handOver(child)">
                                        {{ handingOver === child.membership_id
                                            ? t('handover_starting')
                                            : t('handover_let', childName(child).split(' ')[0]) }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <h3 class="text-uppercase text-muted small">{{ t('section_behaviour') }}</h3>
                        <p v-if="!records[child.membership_id]?.awards?.length" class="text-muted small">
                            {{ t('nothing_recorded') }}
                        </p>
                        <ul v-else class="list-unstyled mb-3">
                            <li v-for="a in records[child.membership_id].awards" :key="a.id" class="d-flex gap-2 align-items-baseline">
                                <span class="badge" :class="a.polarity === 'negative' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success-emphasis'">
                                    {{ a.points > 0 ? '+' : '' }}{{ a.points }}
                                </span>
                                <span class="small" dir="auto">
                                    {{ txAwardSkill(a) }}
                                    <span class="text-muted">· {{ when(a.awarded_at) }}</span>
                                    <span v-if="a.note" class="text-muted"> — {{ txAwardNote(a) }}</span>
                                </span>
                            </li>
                        </ul>

                        <h3 class="text-uppercase text-muted small">{{ t('section_letters') }}</h3>
                        <!-- EVERY TRACK THIS CLASS USES, one under the other,
                             each named and each counted on its own. No switcher
                             here: a parent is reading a record, not marking one,
                             and a control that hides half of what their child
                             has done is a control they have to discover first.
                             The two totals are never added — 12 of 28 Arabic and
                             4 of 26 English is not 16 of 54, which is an
                             alphabet nobody teaches.
                             A track with nothing marked on it is not shown at
                             all (see trackHasWork): the endpoint answers with a
                             full alphabet whether or not the school teaches it,
                             and "English letters — 0 of 26" on a school that
                             teaches no English is a sentence about work that was
                             never set. With neither track marked the v-else
                             below says what the sections above it say. -->
                        <template v-if="letterTracks(child).length">
                            <div v-for="track in letterTracks(child)" :key="track.alphabet" class="mb-3">
                                <div class="d-flex justify-content-between align-items-baseline">
                                    <span class="small fw-semibold">{{ t(`alphabet_${track.alphabet}`) }}</span>
                                    <span class="small text-muted">
                                        {{ track.totals.mastered }} {{ t('count_of') }} {{ track.totals.total }}
                                    </span>
                                </div>
                                <!-- The stage is the qāʿidah's and says what the
                                     class is working on THIS term. The English
                                     track has a single stage whose label would
                                     only repeat the heading above it. -->
                                <div v-if="track.alphabet === 'arabic' && track.stage?.label"
                                     class="text-muted small" dir="auto">{{ stageLabel(track) }}</div>
                                <!-- `.progress` is a flex box, so the bar fills from
                                     the right of its own accord once the page is
                                     RTL. No width maths to flip. -->
                                <div class="progress mt-1" style="height:8px;">
                                    <div class="progress-bar bg-success"
                                         :style="{ width: trackPercent(track) + '%' }"></div>
                                </div>
                                <!-- The direction of the ALPHABET, off the payload
                                     and not of the language the portal is set to:
                                     hijāʾī order runs right to left whichever way
                                     the chrome is pointing, and A–Z runs the other
                                     way in an Arabic portal just the same. -->
                                <div class="d-flex flex-wrap gap-1 mt-2" :dir="track.direction">
                                    <span v-for="l in track.letters" :key="l.id"
                                          class="letter-chip"
                                          :class="[`letter-chip--${l.status}`, { 'letter-chip--pair': l.glyph?.length > 1 }]"
                                          :title="letterTitle(l)">
                                        {{ l.glyph }}
                                    </span>
                                </div>
                            </div>
                        </template>
                        <p v-else class="text-muted small mb-3">{{ t('nothing_recorded') }}</p>

                        <h3 class="text-uppercase text-muted small">{{ t('section_quran') }}</h3>
                        <p v-if="!records[child.membership_id]?.hifz?.length" class="text-muted small mb-0">
                            {{ t('nothing_recorded') }}
                        </p>
                        <ul v-else class="list-unstyled mb-0">
                            <li v-for="h in records[child.membership_id].hifz" :key="h.id" class="small">
                                <span class="text-capitalize">{{ h.kind }}</span>: {{ ayah(h.from) }} {{ rangeArrow }} {{ ayah(h.to) }}
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
                <p v-if="reportsError" class="text-danger small">{{ tMessage(reportsError) }}</p>

                <!-- ------------------------------------------ one open report -->
                <template v-if="openCard">
                    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
                        <button type="button"
                                class="btn btn-link btn-sm p-0 text-decoration-none d-inline-flex align-items-center gap-1"
                                @click="openCard = null">
                            <i :class="backIcon"></i>{{ t('reports_all') }}
                        </button>
                        <!-- The printable copy. A report card is a document a
                             family keeps, and "keeps" for most people means a
                             file or a sheet of paper, not a tab. -->
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                :disabled="downloadingCard"
                                @click="downloadCard">
                            {{ downloadingCard ? t('report_preparing') : t('report_download') }}
                        </button>
                    </div>

                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="fw-semibold" dir="auto">{{ childName(openCardFor) }}</span>
                        <span v-if="openCard.grade_label"
                              class="badge bg-primary-subtle text-primary-emphasis fw-normal" dir="auto">
                            {{ openCard.grade_label }}
                        </span>
                    </div>
                    <div class="text-muted small mb-3">
                        <span dir="auto">{{ openCard.type_label }} &middot; {{ openCard.period_label }}</span>
                        <span v-if="openCard.published_at">&middot; {{ t('report_sent', when(openCard.published_at)) }}</span>
                    </div>

                    <!-- THE KEY, from the payload and never hardcoded. Open by
                         default, unlike the teacher's collapsed copy: a teacher
                         knows the scale by heart, a parent is meeting it for the
                         first time, and "what does a 3 mean?" has to be
                         answerable without emailing the school. Full labels
                         here, not the teacher's abbreviations. The labels and
                         descriptions are the school's own words and are not
                         translated; only the question above them is. -->
                    <details v-if="levelKey.length" class="mb-3" open>
                        <summary class="small text-primary" style="cursor:pointer">
                            {{ t('level_key_summary') }}
                        </summary>
                        <dl class="row small mt-2 mb-0">
                            <template v-for="l in levelKey" :key="l.level">
                                <dt class="col-sm-4 fw-semibold" dir="auto">{{ l.level }} &mdash; {{ l.label }}</dt>
                                <dd class="col-sm-8 text-muted" dir="auto">{{ l.description }}</dd>
                            </template>
                        </dl>
                    </details>

                    <!-- The attendance FROZEN at publication, not recomputed.
                         `present` already includes the late days, so it says so
                         rather than leaving a parent to work out whether the
                         three figures are meant to add up. -->
                    <div v-if="hasAttendance" class="card border-0 bg-light mb-3">
                        <div class="card-body py-2 small">
                            {{ t('attendance_present', String(openCard.attendance.present)) }}
                            <span class="text-muted">{{ t('attendance_late', String(openCard.attendance.late)) }}</span>
                            &middot; {{ t('attendance_absent', String(openCard.attendance.absent)) }}
                        </div>
                    </div>

                    <div v-for="(sub, si) in openCard.subjects" :key="sub.subject" class="card border-0 shadow-sm mb-2">
                        <div class="card-header bg-white fw-semibold small" dir="auto">{{ sub.subject }}</div>
                        <div class="list-group list-group-flush">
                            <!-- Keyed by subject+criterion, NOT by id. The family
                                 payload deliberately carries no mark ids, so a
                                 `:key="m.id"` copied from the teacher's template
                                 would collapse every row onto one undefined key. -->
                            <div v-for="(m, i) in sub.criteria" :key="`${sub.subject}-${m.criterion}-${i}`"
                                 class="list-group-item d-flex align-items-start gap-3 flex-wrap">
                                <div class="flex-grow-1" style="min-width:12rem">
                                    <span class="small" dir="auto">{{ m.criterion }}</span>
                                    <!-- The per-criterion note. A screen that shows
                                         only the card-level comment silently drops
                                         most of what a teacher actually wrote. -->
                                    <div v-if="m.comment" class="text-muted small fst-italic mt-1" dir="auto">{{ txSubjectMark(openCard, si, i, m) }}</div>
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
                        <div class="card-header bg-white fw-semibold small">{{ t('learning_behaviours') }}</div>
                        <div class="list-group list-group-flush">
                            <div v-for="(m, i) in openCard.learning_behaviours" :key="`behaviour-${m.criterion}-${i}`"
                                 class="list-group-item d-flex align-items-start gap-3 flex-wrap">
                                <div class="flex-grow-1" style="min-width:12rem">
                                    <span class="small" dir="auto">{{ m.criterion }}</span>
                                    <div v-if="m.comment" class="text-muted small fst-italic mt-1" dir="auto">{{ txBehaviourMark(openCard, i, m) }}</div>
                                </div>
                                <span class="badge fw-normal flex-shrink-0" :class="levelClass(m)">
                                    {{ levelText(m) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div v-if="openCard.teacher_comment" class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="small text-muted mb-1">{{ t('teacher_comment') }}</div>
                            <p class="mb-0 small report-comment" dir="auto">{{ txCardComment(openCard) }}</p>
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
                                    <h2 class="h6 mb-0" dir="auto">{{ childName(child) }}</h2>
                                </div>

                                <!-- "sent home", never "none published yet". A draft
                                     is a 404 to this realm by design, so the screen
                                     cannot say a report exists but is being withheld
                                     — and must not imply it either. -->
                                <p v-if="!reportCards[child.membership_id]?.length" class="text-muted small mb-0">
                                    {{ t('reports_empty') }}
                                </p>

                                <div v-else class="list-group list-group-flush">
                                    <button v-for="row in reportCards[child.membership_id]" :key="row.id"
                                            type="button"
                                            class="list-group-item list-group-item-action d-flex align-items-center gap-3 px-0"
                                            :disabled="openingCard === row.id"
                                            @click="openReportCard(child, row)">
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold small" dir="auto">{{ row.type_label }}</div>
                                            <div class="text-muted small" dir="auto">{{ row.period_label }}</div>
                                        </div>
                                        <span class="badge bg-success-subtle text-success-emphasis fw-normal">
                                            {{ t('report_sent', when(row.published_at)) }}
                                        </span>
                                        <i class="text-muted" :class="chevronIcon"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>
            </section>

            <!-- ---------------------------------------------------- marks -->
            <!-- The read the "a mark has been posted" email has always implied
                 and never had. Same visual language as the report card above,
                 and for the same reason: NO progress bar and NO colour scale.
                 App\Support\PerformanceLevel argues at length that a standards
                 level shown as a percentage or a red pill is a misreading of
                 it, and painting a 2 red says something the teacher did not
                 say. The teacher's own gradebook has no colour scale either, so
                 inventing one here would be diverging from it rather than
                 matching it.

                 A STATUS IS A SENTENCE, never a number. Work not handed in
                 counts as a zero inside the average — that is what the
                 denominator means — but drawing a "0" beside marks a child
                 actually earned would tell a parent their child scored nothing
                 rather than that nothing arrived. -->
            <section v-else-if="tab === 'grades'">
                <p v-if="gradesError" class="text-danger small">{{ tMessage(gradesError) }}</p>

                <div v-if="gradesLoading && !gradesLoaded" class="text-center py-4">
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
                                <h2 class="h6 mb-0" dir="auto">{{ childName(child) }}</h2>
                            </div>

                            <p v-if="!marksFor(child).scores.length" class="text-muted small mb-0">
                                {{ t('marks_empty') }}
                            </p>

                            <template v-else>
                                <!-- POINTS AND LEVELS ARE NEVER ONE FIGURE. A
                                     class can hold a spelling quiz out of 10 and
                                     a rubric marked 1-4 at once, and the server
                                     summarises the two separately for the reason
                                     the levels scale exists. Each block is shown
                                     only when there is work of that kind: an
                                     empty "0 of 0" is a sentence about work that
                                     was never set. -->
                                <template v-if="marksFor(child).summary.points_counted > 0">
                                    <h3 class="text-uppercase text-muted small">{{ t('marks_section_points') }}</h3>
                                    <p class="small mb-3">
                                        <span class="fw-semibold">
                                            {{ marksFor(child).summary.points_earned }}
                                            {{ t('count_of') }}
                                            {{ marksFor(child).summary.points_possible }}
                                        </span>
                                        <span class="text-muted">
                                            &middot; {{ t('marks_pieces', String(marksFor(child).summary.points_counted)) }}
                                        </span>
                                    </p>
                                </template>

                                <!-- RECORDED, not COUNTED. `missing` levels work
                                     is deliberately outside the mean (see the
                                     server's levelSummary), so a child whose only
                                     levels work has not been handed in has
                                     recorded 3 and counted 0 — and gating the
                                     whole block on `counted` hid the ONE tally
                                     that case has, while the key below (gated on
                                     `recorded`, correctly) still rendered a
                                     legend explaining a scale with nothing above
                                     it. The two gates now agree. -->
                                <template v-if="marksFor(child).summary.levels.recorded > 0">
                                    <h3 class="text-uppercase text-muted small">{{ t('marks_section_levels') }}</h3>
                                    <!-- The NUMBER, with the word beside it and
                                         never instead of it: a 2.5 sits between
                                         two levels, and printing only the nearer
                                         word would tell a parent their child is
                                         one of them.

                                         Inside its own `counted` check: with no
                                         scored levels work the server sends
                                         `mean: null`, and "Average level null" is
                                         worse than no sentence at all. -->
                                    <p v-if="marksFor(child).summary.levels.counted > 0" class="small mb-2">
                                        <span class="fw-semibold">
                                            {{ t('marks_levels_mean', String(marksFor(child).summary.levels.mean)) }}
                                        </span>
                                        <span v-if="marksFor(child).summary.levels.mean_label"
                                              class="text-muted" dir="auto">
                                            &middot; {{ marksFor(child).summary.levels.mean_label }}
                                        </span>
                                    </p>
                                    <!-- Every level, present even at zero, so
                                         the shape does not change as marks come
                                         in and "no 4s yet" is visible rather
                                         than absent. -->
                                    <ul class="list-unstyled small mb-3">
                                        <li v-for="row in marksFor(child).summary.levels.distribution" :key="row.level"
                                            class="d-flex justify-content-between">
                                            <span dir="auto">{{ row.level }} &mdash; {{ row.short_label }}</span>
                                            <span class="text-muted">{{ row.count }}</span>
                                        </li>
                                        <!-- Counted and shown, and deliberately
                                             out of the mean above: 1 is not
                                             "nothing", it is "Needs Support",
                                             which is a judgement nobody made. -->
                                        <li v-if="marksFor(child).summary.levels.missing"
                                            class="d-flex justify-content-between">
                                            <span>{{ t('mark_missing') }}</span>
                                            <span class="text-muted">{{ marksFor(child).summary.levels.missing }}</span>
                                        </li>
                                    </ul>
                                </template>

                                <!-- THE KEY, from the payload and never
                                     hardcoded — the same one the report card
                                     opens with, open by default here too. A
                                     parent meeting a 3 for the first time must
                                     be able to answer "what does a 3 mean?"
                                     without emailing the school. Shown whenever
                                     any levels work exists, including the case
                                     where all of it is missing and there is no
                                     mean to explain. -->
                                <details v-if="levelKey.length && marksFor(child).summary.levels.recorded"
                                         class="mb-3" open>
                                    <summary class="small text-primary" style="cursor:pointer">
                                        {{ t('level_key_summary') }}
                                    </summary>
                                    <dl class="row small mt-2 mb-0">
                                        <template v-for="l in levelKey" :key="l.level">
                                            <dt class="col-sm-4 fw-semibold" dir="auto">{{ l.level }} &mdash; {{ l.label }}</dt>
                                            <dd class="col-sm-8 text-muted" dir="auto">{{ l.description }}</dd>
                                        </template>
                                    </dl>
                                </details>

                                <!-- Newest first, as the server ordered them.
                                     Keyed by assignment id AND position: the
                                     family payload carries no score ids, and
                                     `:key="s.id"` would collapse every row onto
                                     one undefined key — the same trap the report
                                     card's marks document above. -->
                                <ul class="list-unstyled mb-0">
                                    <li v-for="(s, i) in marksFor(child).scores"
                                        :key="`${s.assignment?.id ?? 'x'}-${i}`"
                                        class="border-top py-2">
                                        <div class="d-flex justify-content-between align-items-baseline gap-3">
                                            <div class="flex-grow-1" style="min-width:10rem">
                                                <!-- The teacher's own title for
                                                     the work — a sūrah name, a
                                                     page range — printed as they
                                                     typed it. -->
                                                <div class="small" dir="auto">{{ s.assignment?.title }}</div>
                                                <div class="text-muted small">{{ onDay(s.assignment?.assigned_on) }}</div>
                                            </div>
                                            <span class="badge fw-normal flex-shrink-0" :class="markClass(s)">
                                                {{ markText(s) }}
                                            </span>
                                        </div>
                                        <!-- What the teacher actually wrote, and
                                             the reason a parent opens this at
                                             all. -->
                                        <div v-if="s.note" class="text-muted small fst-italic mt-1" dir="auto">
                                            {{ txMarkNote(child, s, i) }}
                                        </div>
                                    </li>
                                </ul>

                                <!-- A short list under a whole-term average says
                                     so, rather than letting the average look as
                                     though it came from what is visible. -->
                                <p v-if="marksFor(child).scores_truncated" class="text-muted small mt-2 mb-0">
                                    {{ t('marks_truncated', String(marksFor(child).scores_shown)) }}
                                </p>
                            </template>
                        </div>
                    </div>
                </template>
            </section>
        </template>
    </div>

        <!-- A parent choosing their own child's avatar. There is no student
             login, so this is where a child picks their face, with their parent.
             The dir/lang are repeated because this modal is a SECOND root node,
             not a descendant of the div above — the attributes on that one do
             not reach it. -->
        <div v-if="avatarFor" class="modal fade show d-block" tabindex="-1"
             :dir="dir" :lang="lang"
             style="background:rgba(0,0,0,.5)" @click.self="avatarFor = null">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ t('choose_avatar') }}</h5>
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
import { useFamilyLang } from '@/views/family/familyI18n';
import type { FamilyMessage } from '@/views/family/familyI18n';
import { useContentTranslation } from '@/views/family/useContentTranslation';
import type { TranslatableItem } from '@/views/family/useContentTranslation';
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const route = useRoute();
const router = useRouter();
const familyStore = useFamilyStore();
const { lang, isAr, dir, locale, toggle, t, tCount, tMessage, tBoth, tMessageBoth, switchLabel } = useFamilyLang();

const masjidId = computed(() => String(route.params.masjidId));
const groupId = computed(() => String(route.params.groupId));
const base = computed(() => `/api/family/masjids/${masjidId.value}/groups/${groupId.value}`);

/**
 * Direction-bearing glyphs.
 *
 * Bootstrap Icons have no logical variants — `bi-chevron-right` is a picture of
 * an arrow pointing right, and it keeps pointing right under dir="rtl" no
 * matter what the CSS does. So the glyph itself is chosen, not restyled: back
 * points at the start of the line, a chevron points at the screen it opens, and
 * a range arrow runs the way the reader reads.
 */
const backIcon = computed(() => (isAr.value ? 'bi bi-arrow-right' : 'bi bi-arrow-left'));
const chevronIcon = computed(() => (isAr.value ? 'bi bi-chevron-left' : 'bi bi-chevron-right'));
const rangeArrow = computed(() => (isAr.value ? '←' : '→'));

const group = ref<any>(null);
const posts = ref<any[]>([]);
const threads = ref<any[]>([]);
const records = ref<Record<string, any>>({});
const openedThread = ref<any>(null);
const avatarFor = ref<any>(null);
const handingOver = ref<number | null>(null);
/**
 * A child's letter tracks, keyed by membership: one payload per alphabet, in the
 * order they are shown, and only the ones this class actually uses.
 *
 * An array rather than the single payload this used to hold, because a child now
 * has an Arabic tracker AND an English one and they are separate records of
 * separate work. Keeping them apart in the state is what keeps them apart on
 * screen: there is no shape here that a later change could accidentally sum.
 */
const letters = ref<Record<string, any[]>>({});

const letterTracks = (child: any): any[] => letters.value[child.membership_id] ?? [];

/**
 * Has anybody marked anything on this track?
 *
 * The endpoint answers with a WHOLE alphabet either way: `LetterTracker` builds
 * `letters` from the curriculum and joins the child's rows onto it, so a school
 * that has never opened the English tab still gets twenty-six letters back with
 * `totals.mastered: 0`. Rendering that told every parent at such a school
 * "English letters — 0 of 26" for ever, under a progress bar at zero, which
 * reads as a child who has done none of the English work the school set — when
 * the school sets none.
 *
 * `status` is the signal used, and it is chosen because it is the only one in
 * this payload that answers the question. The class `stage` does not: it is the
 * qāʿidah's ladder, and English normalises every class onto its single stage, so
 * it says nothing about whether the track is taught. `totals.mastered` does not
 * either: it counts finished drills, so a class three weeks into a track with
 * nothing mastered yet would be hidden from the parents watching it. A letter is
 * `not_started` only while every drill under it is, so "some letter is past
 * not_started" is exactly "a teacher has marked something here".
 *
 * A child with nothing marked on EITHER track therefore shows no tracks, and the
 * section says "Nothing recorded yet." — the same sentence, from the same
 * payload-driven test, that the behaviour and Qur'an sections above it use.
 */
const trackHasWork = (track: any): boolean =>
    (track?.letters ?? []).some((l: any) => l.status && l.status !== 'not_started');

/**
 * The alphabets this portal asks for, in the order they are shown. Arabic
 * first: it is what this section was, and it is what most of these classes
 * teach. The ids are the server's allowlist — anything else is refused, so a
 * typo here would show a parent an empty section rather than the wrong one.
 */
const LETTER_ALPHABETS = ['arabic', 'english'] as const;

/** One track's bar. Per-alphabet by construction — a track only ever knows its own totals. */
/**
 * The stage caption under the Arabic track.
 *
 * The payload's label is English because the curriculum is defined once, on the
 * server, in one language. An Arabic page printing "The Letters" under الحروف
 * العربية is the seam showing, so the five stage ids have their own entries in
 * familyI18n; anything else falls back to what the server sent, which is still
 * true even when it is not translated.
 */
const stageLabel = (track: any): string => {
    const key = `stage_${track?.stage?.id ?? ''}`;
    const translated = t(key);

    return translated === key ? (track?.stage?.label ?? '') : translated;
};

const trackPercent = (track: any) => {
    const totals = track?.totals;
    return totals && totals.total ? Math.round((totals.mastered / totals.total) * 100) : 0;
};

/**
 * The chip's tooltip: what the letter is called, then how far along it is.
 *
 * `transliteration` is the Arabic letter's Latin name and the English letter's
 * capital, so it reads on both tracks — but it is nullable in the contract, and
 * a tooltip opening with " — mastered" would tell a parent nothing. The glyph is
 * the fallback because it is the thing they are pointing at.
 */
const letterTitle = (l: any) => `${l.transliteration || l.glyph} — ${t(`letter_${l.status}`)}`;

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
        if (!fail(e)) error.value = { key: 'handover_failed' };
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
const tab = ref<'story' | 'messages' | 'children' | 'reports' | 'grades' | 'handouts'>('story');

// ---------- starting a conversation ----------
const composing = ref(false);
const sendingCompose = ref(false);
const composeError = ref<FamilyMessage | null>(null);
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
    composeError.value = null;
    composing.value = true;
};

const createThread = async () => {
    sendingCompose.value = true;
    composeError.value = null;
    try {
        await FamilyApiService.post(`${base.value}/threads`, {
            subject: composeForm.value.subject,
            about_membership_id: composeForm.value.about_membership_id,
            body: composeForm.value.body,
        });
        composing.value = false;
        // There is no standalone thread loader in this view — the list is
        // refreshed by re-reading the endpoint the class load uses.
        const refreshed = await FamilyApiService.get(`${base.value}/threads`);
        threads.value = rowsOf(refreshed.data?.data);
    } catch (e: any) {
        // The 429 is ours to word, because the throttle is a portal rule a
        // parent has to be able to act on. Anything else the API chose to
        // explain is shown in the API's own words.
        const served = e?.response?.data?.message ?? e?.response?.data?.data?.subject?.[0];
        composeError.value = e?.response?.status === 429
            ? { key: 'compose_rate_limited' }
            : (served ? { text: served } : { key: 'compose_failed' });
    } finally {
        sendingCompose.value = false;
    }
};

// Handouts the class chose to share. The API applies visibility as a SCOPE, so
// a staff-only file is never in this list and its name is never in this payload.
const handouts = ref<any[]>([]);
const handoutsError = ref<FamilyMessage | null>(null);

const loadHandouts = async () => {
    handoutsError.value = null;
    try {
        const res = await FamilyApiService.get(`${base.value}/resources`);
        handouts.value = res.data?.data ?? [];
    } catch {
        handoutsError.value = { key: 'handouts_error' };
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
        handoutsError.value = { key: 'handout_download_failed' };
    }
};

// The parameter is `next`, not `t`: `t` is the translate function in this
// file's scope now, and a local of that name would shadow it for anything
// added inside.
watch(tab, (next) => {
    if (next === 'handouts' && !handouts.value.length) loadHandouts();
    // Guarded on `reportsLoaded`, not on an empty list: having no reports yet is
    // the ordinary state for most of a school year, so a length check would
    // refetch on every visit to the tab. `reportsLoading` stops a double-tap
    // firing two rounds of requests.
    if (next === 'reports' && !reportsLoaded.value && !reportsLoading.value) loadReportCards();
    // Same guard shape as the reports tab, and for the same reason: having no
    // marks yet is the ordinary state at the start of a term, so a length check
    // would refetch every child's record on every visit to the tab.
    if (next === 'grades' && !gradesLoaded.value && !gradesLoading.value) loadGrades();
});
const loading = ref(true);
const error = ref<FamilyMessage | null>(null);

const childName = (child: any) =>
    [child?.contact?.first_name ?? child?.first_name, child?.contact?.last_name ?? child?.last_name]
        .filter(Boolean).join(' ') || t('student');

/** `from` / `to` arrive as {surah, surah_name, ayah, juz}, not a string. */
const ayah = (ref: any) => {
    if (!ref) return '';
    if (typeof ref === 'string') return ref;
    return `${ref.surah_name ?? `${t('surah')} ${ref.surah}`} ${ref.ayah}`;
};

// The locale comes from the composable, so an Arabic reader gets Arabic month
// names — `ar-u-nu-latn`, so the digits still match every other number the
// server prints on the same screen.
const when = (iso: string | null) => {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString(locale.value, { month: 'short', day: 'numeric', year: 'numeric' });
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
const replyError = ref<FamilyMessage | null>(null);

const sendReply = async () => {
    const body = replyBody.value.trim();
    if (!body || !openedThread.value) return;

    sendingReply.value = true;
    replyError.value = null;
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
        const refreshed = await FamilyApiService.get(`${base.value}/threads`);
        threads.value = rowsOf(refreshed.data?.data);
    } catch (e: any) {
        if (fail(e)) return;
        const served = e?.response?.data?.message || e?.response?.data?.data?.body?.[0];
        replyError.value = served ? { text: served } : { key: 'reply_failed' };
    } finally {
        sendingReply.value = false;
    }
};

const openThread = async (thread: any) => {
    try {
        const res = await FamilyApiService.get(`${base.value}/threads/${thread.id}`);
        openedThread.value = res.data?.data?.thread ?? thread;
        replyBody.value = '';
        replyError.value = null;
        openedMessages.value = rowsOf(res.data?.data?.messages);
    } catch (e) {
        if (!fail(e)) error.value = { key: 'thread_open_failed' };
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

            // Both alphabets, asked for separately because they ARE separate
            // records — same route, same ward-edge gate, one `?alphabet=` apart.
            // Each is caught on its own: a track that fails to load must not
            // take down the one that did, or a parent whose child has a full
            // qāʿidah page would be told nothing is recorded.
            const tracks = await Promise.all(LETTER_ALPHABETS.map(async (alphabet) => {
                try {
                    const l = await FamilyApiService.get(
                        `${base.value}/members/${child.membership_id}/letters?alphabet=${alphabet}`
                    );
                    return l.data?.data ?? null;
                } catch {
                    // A class with no letter work is not an error; the card
                    // simply says nothing is recorded yet.
                    return null;
                }
            }));

            // Only the tracks with work on them. The payload is full whichever
            // way the class teaches, so this is where a school that does not
            // use a track stops being shown an empty one — see trackHasWork().
            letters.value[child.membership_id] = tracks.filter((track) => track && trackHasWork(track));
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
const reportsError = ref<FamilyMessage | null>(null);
const levelKey = ref<any[]>([]);
const openCard = ref<any>(null);
const openCardFor = ref<any>(null);
const openingCard = ref<number | null>(null);

const loadReportCards = async () => {
    reportsLoading.value = true;
    reportsError.value = null;

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
                reportsError.value = { key: 'reports_partial_error' };
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
    reportsError.value = null;

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
        if (!fail(e)) reportsError.value = { key: 'report_open_failed' };
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
        // The filename stays as the school named the report. A parent filing
        // this alongside the PDF the office emailed them should see one name,
        // not two, so it does not follow the portal's language.
        a.download = `${childName(openCardFor.value)} - ${openCard.value.period_label}.pdf`;
        a.click();
        URL.revokeObjectURL(url);
    } catch (e) {
        if (!fail(e)) reportsError.value = { key: 'report_download_failed' };
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
    if (m?.level === null || m?.level === undefined) return t('not_assessed');

    // The short label is the school's own wording for the level and is printed
    // as it arrives, in either language.
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

// ---------- marks ----------
//
// One request per child, because the endpoint is per-child by design: there is
// no group-wide variant of a gradebook read in this realm, and there is not
// going to be one. A class-wide view of marks is the comparison the whole module
// refuses to make, and an API shaped to allow it would suggest otherwise even
// while the audience rules held.
const grades = ref<Record<number, any>>({});
const gradesLoaded = ref(false);
const gradesLoading = ref(false);
const gradesError = ref<FamilyMessage | null>(null);

/**
 * One child's marks, with a shape the template can always read.
 *
 * The empty record is returned rather than null so every access below is
 * `.summary.levels.counted` instead of a chain of `?.` that would silently
 * render nothing if the payload ever changed shape. A child with no marks is a
 * real and common state — most of a term starts there — and it renders the
 * empty sentence, not a blank card.
 */
const EMPTY_MARKS = {
    summary: {
        recorded: 0, counted: 0, excused: 0,
        points_earned: 0, points_possible: 0, points_counted: 0,
        levels: { recorded: 0, counted: 0, missing: 0, mean: null, mean_label: null, distribution: [] },
    },
    scores: [],
    scores_shown: 0,
    scores_truncated: false,
};

const marksFor = (child: any): any => grades.value[child.membership_id] ?? EMPTY_MARKS;

const loadGrades = async () => {
    gradesLoading.value = true;
    gradesError.value = null;

    try {
        for (const child of group.value?.children ?? []) {
            try {
                const res = await FamilyApiService.get(
                    `${base.value}/members/${child.membership_id}/grades`,
                );
                grades.value[child.membership_id] = res.data?.data ?? EMPTY_MARKS;
                // `performance_levels` is a SIBLING of `data`, not a member of
                // it. The `?? existing` keeps a key already loaded by the
                // reports tab rather than blanking the legend if a response ever
                // arrives without one.
                levelKey.value = res.data?.performance_levels ?? levelKey.value;
            } catch (e) {
                if (fail(e)) return;
                // Caught INSIDE the loop: one sibling's failure must not blank
                // the other's marks, which is the same reason loadReportCards()
                // and loadChildRecords() catch here rather than around the loop.
                grades.value[child.membership_id] = EMPTY_MARKS;
                gradesError.value = { key: 'marks_error' };
            }
        }

        gradesLoaded.value = true;
    } finally {
        gradesLoading.value = false;
    }
};

/**
 * A date with no time on it.
 *
 * `assigned_on` is a plain "2026-09-05", and `new Date()` reads a bare date as
 * UTC midnight — which in every timezone west of Greenwich renders as the day
 * before. Every other date on this screen carries a time and goes through
 * `when()`; this one has to be pinned to LOCAL midnight first, or a parent in
 * Carolina is told the work was set on the 4th.
 */
const onDay = (day: string | null | undefined) => (day ? when(`${day}T00:00:00`) : '');

/**
 * What one mark SAYS.
 *
 * Three statuses, three different sentences, which is why the column has three
 * values rather than a nullable number:
 *
 *   - `missing` is "Not handed in". It counts as a zero inside the average,
 *     because work not done is work not done, and it must still never be drawn
 *     as a "0" beside marks the child earned — one of those is a statement about
 *     a child's work and the other is a statement about their understanding.
 *   - `excused` is "Excused" and counts in neither half of the average: the
 *     office accepted the absence, and a number here would imply otherwise.
 *   - `scored` is the mark, in the units it was marked in — "8 of 10" on the
 *     points scale, and the LEVEL with the school's own word for it on the
 *     levels scale. Never a percentage of four.
 */
const markText = (s: any): string => {
    if (s?.status === 'missing') return t('mark_missing');
    if (s?.status === 'excused') return t('mark_excused');
    if (s?.points_earned === null || s?.points_earned === undefined) return t('not_assessed');

    if (s.assignment?.scale === 'levels') {
        const short = levelKey.value.find((l: any) => l.level === s.points_earned)?.short_label;
        return `${s.points_earned} · ${short ?? ''}`.trim().replace(/ ·$/, '');
    }

    return `${s.points_earned} ${t('count_of')} ${s.assignment?.points_possible ?? ''}`.trim();
};

/**
 * Two neutral pills and no third. There is deliberately no red, no amber and no
 * green: a low mark is a fact a teacher recorded, and colouring it is a judgement
 * this screen is not entitled to add on their behalf.
 */
const markClass = (s: any): string =>
    s?.status === 'scored'
        ? 'bg-primary-subtle text-primary-emphasis'
        : 'bg-light text-muted';

// ---------- translating what the school wrote ----------
//
// The chrome follows the language toggle (familyI18n.ts). This is the other
// button: the teachers here write in English, a good number of the parents read
// Arabic, and this turns the words the SCHOOL wrote — a class story, a message
// from a teacher, a report-card comment — into Arabic on demand.
//
// On demand, and never on arrival, even when the chrome is already Arabic. A
// parent reading the portal in Arabic has not asked for a paid model to rewrite
// thirty paragraphs; many of them read both languages perfectly well and would
// simply be spending the masjid's money on every page they opened. So the
// entry point is one tap, and the argument is set out in full in
// useContentTranslation.ts.

/**
 * The key every staff-written string on this screen travels under.
 *
 * Gathered in one object because the SAME key has to be produced twice — once
 * when the string is collected for the request, once when it is rendered — and
 * the two are three hundred lines apart. A key built by hand in the template
 * that disagreed with the one built by hand in the collector would fail
 * silently and in the worst possible way: the request succeeds, the response is
 * complete, nothing is reported, and the paragraph renders in English forever
 * because the map is being asked for a key nobody stored.
 *
 * Shapes are `<type>:<id>:<field>`. The ids are the server's own row ids, so a
 * post and a message numbered 12 cannot collide, and the key travels to an
 * endpoint that never interprets it. Report-card marks are the exception and
 * are addressed BY POSITION — the family payload deliberately carries no mark
 * ids (see the template) — which is stable because a published report card is
 * frozen: its subjects and criteria cannot be reordered underneath a parent.
 */
const KEY = {
    groupDescription: () => `group:${groupId.value}:description`,
    post: (post: any, field: 'title' | 'body') => `post:${post.id}:${field}`,
    threadSubject: (thread: any) => `thread:${thread.id}:subject`,
    messageBody: (message: any) => `message:${message.id}:body`,
    handout: (handout: any, field: 'title' | 'description') => `resource:${handout.id}:${field}`,
    awardSkill: (award: any) => `award:${award.id}:skill`,
    awardNote: (award: any) => `award:${award.id}:note`,
    cardComment: (card: any) => `report:${card.id}:teacher_comment`,
    subjectMark: (card: any, subject: number, mark: number) =>
        `report:${card.id}:subject:${subject}:mark:${mark}:comment`,
    behaviourMark: (card: any, mark: number) => `report:${card.id}:behaviour:${mark}:comment`,
    /**
     * A teacher's note on one mark, addressed by CHILD, assignment and position.
     *
     * The family payload carries no score ids, so the key has to be built from
     * what is there — the same problem the report-card marks above solve by
     * position. The membership id is the part that is easy to leave out and must
     * not be: two siblings in one classroom are marked on the SAME assignments,
     * so `grade:9:0:note` would name Amina's note and Bilal's note at once, and
     * whichever was collected second would render under both. That is not a
     * missing translation — it is one child's teacher comment appearing on the
     * other child's card, which is the exact disclosure this portal is built to
     * prevent, arriving through the translation map instead of through the API.
     */
    markNote: (membershipId: number | string, score: any, i: number) =>
        `grade:${membershipId}:${score.assignment?.id ?? 'x'}:${i}:note`,
};

const {
    loading: translating,
    error: translationError,
    incomplete: translationIncomplete,
    showOriginal,
    showing: translationShowing,
    hasTranslations: hasTranslated,
    available: translationAvailable,
    setAvailable: setTranslationAvailable,
    translate,
    tx,
} = useContentTranslation(masjidId, { onAuthFailure: fail });

/**
 * Everything on this screen that a member of STAFF wrote as prose.
 *
 * What is deliberately absent matters more than what is here. A child's name, a
 * teacher's name, a date, a points total, a Surah reference, an attendance
 * figure, a file name and every label this portal prints itself are all left
 * out: they are either not English, not prose, or not the school's to reword —
 * and a name run through a translator comes back as something the office would
 * not recognise if the parent rang up and read it out. The class NAME is out
 * for exactly that reason, though its description is in: the name is the handle
 * a family uses when they phone the school, and it has to survive being read
 * aloud in either language.
 *
 * ONLY WHAT IS ON SCREEN IS COLLECTED, and the tab is what decides that.
 *
 * "What is loaded" is not the same thing and was the wrong test: `onMounted`
 * calls `loadChildRecords()` unconditionally, so a parent sitting on the class
 * story already has a term of behaviour awards for every child in `records` —
 * three children and a term of points is two hundred strings — and collecting
 * them here made the first tap on `story` buy the whole behaviour ledger. It
 * was slow, it cost the school money for text nobody was looking at, and it
 * could spend the 20-a-minute allowance before the class story itself was
 * translated. So each tab's content is gated on that tab being the open one,
 * and the class description — which is rendered above the tabs on every one of
 * them — is the only thing collected unconditionally.
 *
 * Nothing is lost by the gate: `watch(translatableItems, ...)` below re-calls
 * `translate()` when the list changes, so opening a tab while translations are
 * showing translates that tab, and coming back to one already translated costs
 * nothing because every key is already in the map.
 */
const translatableItems = computed<TranslatableItem[]>(() => {
    const items: TranslatableItem[] = [];

    const add = (key: string, text: unknown) => {
        if (typeof text === 'string' && text.trim() !== '') {
            items.push({ key, text });
        }
    };

    // Above the tabs, so on screen whichever one is open.
    add(KEY.groupDescription(), group.value?.description);

    if (tab.value === 'story') {
        for (const post of posts.value) {
            add(KEY.post(post, 'title'), post.title);
            add(KEY.post(post, 'body'), post.body);
        }
    }

    if (tab.value === 'messages') {
        for (const thread of threads.value) {
            add(KEY.threadSubject(thread), thread.subject);
        }

        // The open conversation only. The list carries subjects, not bodies, so
        // there is nothing else to collect until a parent opens one — and when
        // they do, the watcher below picks the messages up.
        for (const message of openedMessages.value) {
            add(KEY.messageBody(message), message.body);
        }
    }

    if (tab.value === 'handouts') {
        for (const handout of handouts.value) {
            add(KEY.handout(handout, 'title'), handout.title);
            add(KEY.handout(handout, 'description'), handout.description);
        }
    }

    if (tab.value === 'children') {
        for (const record of Object.values(records.value)) {
            for (const award of record?.awards ?? []) {
                // The skill label is the school's own wording for the behaviour,
                // so it is staff-written text and not a UI label of ours.
                add(KEY.awardSkill(award), award.skill_label);
                add(KEY.awardNote(award), award.note);
            }
        }
    }

    if (tab.value === 'grades') {
        // ONLY THE NOTES. An assignment title is a sūrah name, a page range or
        // "Qāʿidah p. 12" — not prose, and a translator would mangle it into
        // something the parent could not match against what their child brought
        // home. The same argument this file already makes for names and for the
        // class name applies, with the extra edge that a mistranslated title is
        // harder to spot as wrong than a mistranslated sentence.
        for (const [membershipId, record] of Object.entries(grades.value)) {
            (record?.scores ?? []).forEach((score: any, i: number) => {
                add(KEY.markNote(membershipId, score, i), score.note);
            });
        }
    }

    // The open report card, which only the reports tab can be showing: the tab
    // watcher at the foot of this file closes it on the way out.
    const card = tab.value === 'reports' ? openCard.value : null;

    if (card) {
        add(KEY.cardComment(card), card.teacher_comment);

        (card.subjects ?? []).forEach((subject: any, s: number) => {
            (subject.criteria ?? []).forEach((mark: any, m: number) => {
                add(KEY.subjectMark(card, s, m), mark.comment);
            });
        });

        (card.learning_behaviours ?? []).forEach((mark: any, m: number) => {
            add(KEY.behaviourMark(card, m), mark.comment);
        });
    }

    return items;
});

/**
 * Content that arrives AFTER the parent asked.
 *
 * This is not the automatic translation the composable refuses to do. The
 * parent has already tapped, and the button now reads "Show original" — so
 * opening a conversation, a handout list or a report card and being handed
 * English underneath that label would be the screen lying about its own state.
 * Everything already translated is skipped inside `translate()`, so this costs
 * a request only for what is genuinely new, and nothing at all until the tap.
 */
watch(translatableItems, (items) => {
    if (!translationShowing.value) return;

    translate(items);
});

const onTranslate = async () => {
    if (translationShowing.value) {
        showOriginal.value = true;
        return;
    }

    showOriginal.value = false;
    await translate(translatableItems.value);
};

// Read helpers, one per key shape. Nothing but `tx(KEY.x(...), original)`, and
// they exist so the template names a field once instead of repeating a key
// expression that has to match the collector above character for character.
const txPost = (post: any, field: 'title' | 'body') => tx(KEY.post(post, field), post[field]);
const txThreadSubject = (thread: any) => tx(KEY.threadSubject(thread), thread.subject);
const txMessageBody = (message: any) => tx(KEY.messageBody(message), message.body);
const txHandout = (handout: any, field: 'title' | 'description') => tx(KEY.handout(handout, field), handout[field]);
const txAwardSkill = (award: any) => tx(KEY.awardSkill(award), award.skill_label);
const txAwardNote = (award: any) => tx(KEY.awardNote(award), award.note);
const txGroupDescription = () => tx(KEY.groupDescription(), group.value?.description);
const txCardComment = (card: any) => tx(KEY.cardComment(card), card.teacher_comment);
const txSubjectMark = (card: any, subject: number, mark: number, row: any) =>
    tx(KEY.subjectMark(card, subject, mark), row.comment);
const txBehaviourMark = (card: any, mark: number, row: any) =>
    tx(KEY.behaviourMark(card, mark), row.comment);
// The child is part of the key, not decoration — see KEY.markNote.
const txMarkNote = (child: any, score: any, i: number) =>
    tx(KEY.markNote(child.membership_id, score, i), score.note);

onMounted(async () => {
    try {
        const res = await FamilyApiService.get(base.value);
        group.value = res.data?.data ?? null;

        // Whether this deployment can translate at all. Read from the envelope
        // every family response carries, so the button is never offered where
        // the only thing it could do is fail.
        setTranslationAvailable(res.data?.meta?.translation_available);

        // The feed is consent-gated; asking for it without consent is a 403 the
        // parent has already been told about, so do not ask.
        if (group.value?.may_receive_feed) {
            const p = await FamilyApiService.get(`${base.value}/posts`);
            posts.value = rowsOf(p.data?.data);
        }

        const refreshed = await FamilyApiService.get(`${base.value}/threads`);
        threads.value = rowsOf(refreshed.data?.data);

        await loadChildRecords();
    } catch (e: any) {
        if (!fail(e)) error.value = { key: 'class_load_error' };
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
/* An English chip carries the case PAIR ("Aa") — the two forms are the thing
   being learned, so showing one would be showing half the letter. Two glyphs do
   not fit a square sized for one Arabic letter, so the chip grows sideways
   rather than clipping. Keyed on the glyph's own length, not on the alphabet,
   so anything else that ships a compound glyph is drawn correctly too. */
.letter-chip--pair { width: auto; padding: 0 7px; font-size: 14px; letter-spacing: .5px; }

/* A teacher's comment is 4000 characters of free text, and where they put the
   line breaks is part of what they wrote. */
.report-comment { white-space: pre-wrap; }

/* ------------------------------------------------------------------ RTL
   This app loads the LTR build of Bootstrap 5 only, and in that build the
   "logical"-sounding utilities are physical: `.text-start` compiles to
   `text-align: left`, `.ms-1` to `margin-left`. Under dir="rtl" they pin
   content to the wrong edge while reading, in the markup, as though they
   should flip. The flex utilities (`justify-content-*`, `align-self-*`,
   `gap-*`) DO follow direction on their own and are used unchanged; the three
   places that needed a side are given real logical properties below rather
   than a [dir="rtl"] override, so they are right in both directions and there
   is no second copy of the rule to keep in step.

   `text-align: start` on the bubble matters beyond tidiness: the body carries
   dir="auto", so an English message inside an Arabic portal resolves to LTR on
   its own element, and `start` follows THAT rather than the page around it —
   which a [dir="rtl"] override could not do.

   Anything added to this template using a physical Bootstrap utility (the
   ms- and me- margins, text-start, text-end) will not
   flip. Give it a logical property here instead. */
.align-start { text-align: start; }
.align-end { text-align: end; }
.badge-after { margin-inline-start: .25rem; }

/* Bootstrap pushes the modal's close button across with the physical
   `margin: -.5rem -.5rem -.5rem auto`, which under RTL crowds it against the
   title instead of the far edge. The attribute selector scoped styles add
   makes this specific enough to win without !important. */
.modal-header .btn-close {
    margin-inline-start: auto;
    margin-inline-end: -.5rem;
}
</style>
