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

            <!-- Seven tabs already filled a one-row scroller; ten would put the
                 last three off-screen with nothing to say they exist. The seven
                 a teacher has muscle memory for do not move, and the new three
                 sit behind More — which renders the ACTIVE one's label, or the
                 teacher loses their place. -->
            <!-- The seven scroll; "More" sits OUTSIDE that scroller.
                 An absolutely-positioned menu inside an `overflow-auto` ancestor
                 is clipped by it — the menu opened and was simply invisible,
                 which reads exactly like a dead button. It is also driven by
                 component state rather than data-bs-toggle, so it cannot depend
                 on Bootstrap's JS having initialised. -->
            <div class="d-flex align-items-end gap-2 mb-4 border-bottom position-relative">
                <ul class="nav nav-tabs flex-nowrap overflow-auto flex-grow-1 border-0">
                    <li v-for="t in tabs" :key="t.key" class="nav-item">
                        <button type="button" class="nav-link text-nowrap"
                                :class="{ active: activeTab === t.key }" @click="activeTab = t.key">
                            <i :class="`bi ${t.icon} me-1`"></i>{{ t.label }}
                        </button>
                    </li>
                </ul>

                <div class="flex-shrink-0 position-relative" @click.stop>
                    <button type="button" class="btn btn-sm text-nowrap mb-1"
                            :class="activeMoreTab ? 'btn-success' : 'btn-outline-secondary'"
                            @click="moreOpen = !moreOpen">
                        <i :class="`bi ${activeMoreTab?.icon ?? 'bi-three-dots'} me-1`"></i>
                        {{ activeMoreTab ? activeMoreTab.label : 'More' }}
                        <i class="bi bi-chevron-down ms-1 small"></i>
                    </button>

                    <div v-if="moreOpen"
                         class="position-absolute end-0 mt-1 bg-white border rounded-3 shadow py-1"
                         style="min-width: 12rem; z-index: 1080;">
                        <button v-for="t in moreTabs" :key="t.key" type="button"
                                class="btn btn-sm w-100 text-start border-0 rounded-0 px-3 py-2"
                                :class="activeTab === t.key ? 'bg-success-subtle text-success-emphasis fw-semibold' : ''"
                                @click="activeTab = t.key; moreOpen = false">
                            <i :class="`bi ${t.icon} me-2`"></i>{{ t.label }}
                        </button>
                    </div>
                </div>
            </div>

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
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-semibold small">{{ name(s.contact) }}</span>
                                <!-- A combined class still teaches more than one grade. -->
                                <span v-if="s.grade_label"
                                      class="badge bg-primary-subtle text-primary-emphasis fw-normal">
                                    {{ s.grade_label }}
                                </span>
                            </div>
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

            <!-- ================================================= ATTENDANCE -->
            <section v-else-if="activeTab === 'attendance'">
                <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
                    <div>
                        <label class="form-label small mb-1">Day</label>
                        <input type="date" class="form-control form-control-sm" style="width: 170px"
                               v-model="attDate" :max="todayIso" @change="loadAttendance" />
                    </div>
                    <div class="flex-grow-1"></div>
                    <button class="btn btn-sm btn-outline-secondary" :disabled="attLoading || !students.length"
                            @click="markAllPresent">
                        <i class="bi bi-check2-all me-1"></i>All present
                    </button>
                </div>

                <div v-if="attLoading" class="text-muted small">Loading the register…</div>
                <div v-else-if="!students.length" class="text-muted small">No students on this roster yet.</div>
                <template v-else>
                    <div class="small mb-2" :class="attTaken ? 'text-success' : 'text-muted'">
                        <i :class="`bi ${attTaken ? 'bi-check-circle' : 'bi-circle'} me-1`"></i>
                        {{ attTaken ? 'Register taken for this day.' : 'Not taken yet for this day.' }}
                    </div>

                    <div class="list-group mb-3">
                        <div v-for="s in students" :key="s.membership_id"
                             class="list-group-item d-flex align-items-center gap-3 flex-wrap">
                            <PersonAvatar :avatar="s.contact?.avatar"
                                          :first-name="s.contact?.first_name" :last-name="s.contact?.last_name" :size="36" />
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-semibold small">{{ name(s.contact) }}</span>
                                    <span v-if="s.grade_label"
                                          class="badge bg-primary-subtle text-primary-emphasis fw-normal">
                                        {{ s.grade_label }}
                                    </span>
                                </div>
                            </div>
                            <div class="btn-group btn-group-sm" role="group" :aria-label="`Mark ${name(s.contact)}`">
                                <button v-for="opt in ATT_OPTIONS" :key="opt.value" type="button"
                                        class="btn" :class="marks[s.membership_id] === opt.value ? opt.on : opt.off"
                                        :title="opt.label" :aria-pressed="marks[s.membership_id] === opt.value"
                                        @click="marks[s.membership_id] = opt.value">
                                    {{ opt.short }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <button class="btn btn-success btn-sm" :disabled="attSaving || !markedCount"
                                @click="saveAttendance">
                            <i class="bi bi-save me-1"></i>
                            {{ attSaving ? 'Saving…' : `Save register (${markedCount}/${students.length})` }}
                        </button>
                        <span v-if="attSaved" class="text-success small">
                            <i class="bi bi-check-circle me-1"></i>Saved
                        </span>
                        <span v-if="attError" class="text-danger small">{{ attError }}</span>
                    </div>
                </template>
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
                                <p v-if="letterError" class="text-danger small mt-2 mb-0">{{ letterError }}</p>
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
                                    <label class="form-label small text-muted mb-1">Type</label>
                                    <!-- Plain English only. The STORED values are still
                                         sabak/sabqi/manzil; this is presentation.
                                         Not "New lesson" for sabak, natural as that
                                         reads, because there is now a Lesson Plans tab
                                         and the two would be read as the same thing. -->
                                    <select class="form-select form-select-sm" style="min-width:12rem" v-model="hifzForm.kind">
                                        <option value="sabak">New memorization</option>
                                        <option value="sabqi">Recent revision</option>
                                        <option value="manzil">Older revision</option>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Surah</label>
                                    <select class="form-select form-select-sm" style="min-width:14rem" v-model.number="hifzForm.surah">
                                        <option :value="null" disabled>Choose a surah…</option>
                                        <option v-for="s in surahs" :key="s.number" :value="s.number">
                                            {{ s.number }} · {{ s.name }} ({{ s.ayahs }})
                                        </option>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Ayahs</label>
                                    <div class="d-flex align-items-center gap-1">
                                        <input type="number" min="1" :max="surahAyahs" class="form-control form-control-sm"
                                               style="width:4.5rem" v-model.number="hifzForm.from_ayah" placeholder="from">
                                        <span class="text-muted small">to</span>
                                        <input type="number" min="1" :max="surahAyahs" class="form-control form-control-sm"
                                               style="width:4.5rem" v-model.number="hifzForm.to_ayah" placeholder="to">
                                    </div>
                                </div>
                                <div v-if="ayahCount" class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">&nbsp;</label>
                                    <div class="small text-success fw-semibold pt-1">{{ ayahCount }} āyah{{ ayahCount === 1 ? '' : 's' }}</div>
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

                    <!-- The key. It exists because picking the wrong type does not just
                         mislabel a row: HifzProgress advances a child's position from
                         SABAK ALONE, so revision logged as new memorisation moves the
                         child forward in what the family sees. -->
                    <div class="border rounded-3 px-3 py-2 mb-3 bg-body-tertiary">
                        <div class="small fw-semibold text-muted mb-1">Which type do I choose?</div>
                        <dl class="row mb-0 small">
                            <dt class="col-sm-4 col-lg-3 fw-semibold">New memorization</dt>
                            <dd class="col-sm-8 col-lg-9 mb-1">
                                The new portion the child memorized today.
                                <span class="text-success-emphasis fw-semibold">Only this moves them forward.</span>
                            </dd>
                            <dt class="col-sm-4 col-lg-3 fw-semibold">Recent revision</dt>
                            <dd class="col-sm-8 col-lg-9 mb-1">Recently memorized portions, gone over again while still fresh.</dd>
                            <dt class="col-sm-4 col-lg-3 fw-semibold">Older revision</dt>
                            <dd class="col-sm-8 col-lg-9 mb-0">Everything memorized earlier, cycled through so it is not lost.</dd>
                        </dl>
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
                <p class="text-muted small">
                    Conversations with your families. Start one with a family, or reply to a thread.
                </p>

                <div v-if="!openedThread" class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <button v-if="!composing" class="btn btn-sm btn-success" @click="startCompose">
                            <i class="bi bi-pencil-square me-1"></i>New message
                        </button>

                        <template v-else>
                            <div class="row g-2">
                                <div class="col-12 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">About</label>
                                    <select class="form-select form-select-sm" style="min-width:13rem"
                                            v-model="composeForm.about_membership_id">
                                        <option :value="null">Whole class</option>
                                        <option v-for="s in students" :key="s.membership_id" :value="s.membership_id">
                                            {{ name(s.contact) }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-12 col-sm">
                                    <label class="form-label small text-muted mb-1">Subject</label>
                                    <input v-model="composeForm.subject" type="text" maxlength="255"
                                           class="form-control form-control-sm" placeholder="e.g. Settling in well">
                                </div>
                            </div>

                            <textarea v-model="composeForm.body" rows="3" maxlength="5000"
                                      class="form-control form-control-sm mt-2"
                                      placeholder="Your first message…"></textarea>

                            <p class="text-muted small mt-2 mb-2">
                                <template v-if="composeForm.about_membership_id">
                                    Only that child's guardians will see this.
                                </template>
                                <template v-else>
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    Every family in this class will see this conversation.
                                </template>
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

            <!-- ================================================ LESSON PLANS -->
            <section v-else-if="activeTab === 'lessons'">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-secondary" @click="shiftWeek(-1)">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <span class="small fw-semibold">{{ weekLabel }}</span>
                    <button class="btn btn-sm btn-outline-secondary" @click="shiftWeek(1)">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>

                <div class="d-flex gap-1 mb-3 flex-wrap">
                    <button v-for="d in weekDays" :key="d.iso" type="button"
                            class="btn btn-sm" :class="d.iso === planDate ? 'btn-success' : (planFor(d.iso) ? 'btn-outline-success' : 'btn-outline-secondary')"
                            @click="planDate = d.iso">
                        {{ d.label }}
                        <i v-if="planFor(d.iso)" class="bi bi-dot"></i>
                    </button>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <input v-model="planForm.title" type="text" maxlength="255"
                               class="form-control form-control-sm mb-2" placeholder="Title (optional)">
                        <textarea v-model="planForm.body" rows="7" class="form-control form-control-sm"
                                  placeholder="What will this class cover?"></textarea>
                        <div class="d-flex align-items-center gap-2 mt-2">
                            <button class="btn btn-sm btn-success" :disabled="planSaving || !planForm.body.trim()"
                                    @click="savePlan">
                                {{ planSaving ? 'Saving…' : 'Save plan' }}
                            </button>
                            <button v-if="planFor(planDate)" class="btn btn-sm btn-link text-danger"
                                    @click="deletePlan">Remove</button>
                            <span v-if="planSaved" class="text-success small">
                                <i class="bi bi-check-circle me-1"></i>Saved
                            </span>
                            <span v-if="planError" class="text-danger small">{{ planError }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ======================================================= GRADES -->
            <section v-else-if="activeTab === 'grades'">
                <template v-if="!openAssignment">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-sm">
                                    <label class="form-label small text-muted mb-1">New work</label>
                                    <input v-model="assignmentForm.title" type="text" maxlength="200"
                                           class="form-control form-control-sm" placeholder="e.g. Spelling test">
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Out of</label>
                                    <input v-model.number="assignmentForm.points_possible" type="number" min="1"
                                           class="form-control form-control-sm" style="width:6rem">
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Set on</label>
                                    <input v-model="assignmentForm.assigned_on" type="date"
                                           class="form-control form-control-sm" style="width:10rem">
                                </div>
                                <div class="col-auto">
                                    <button class="btn btn-sm btn-success"
                                            :disabled="creatingAssignment || !assignmentForm.title.trim()"
                                            @click="createAssignment">Add</button>
                                </div>
                            </div>
                            <p v-if="gradesError" class="text-danger small mt-2 mb-0">{{ gradesError }}</p>
                        </div>
                    </div>

                    <p v-if="!assignments.length" class="text-muted small">No work set yet.</p>
                    <div v-else class="list-group">
                        <button v-for="a in assignments" :key="a.id" type="button"
                                class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                                @click="openScores(a)">
                            <div class="flex-grow-1">
                                <div class="fw-semibold small">{{ a.title }}</div>
                                <div class="text-muted small">{{ a.assigned_on }} · out of {{ a.points_possible }}</div>
                            </div>
                            <span class="badge" :class="a.scored >= a.roster ? 'bg-success-subtle text-success-emphasis' : 'bg-light text-muted'">
                                {{ a.scored }}/{{ a.roster }} marked
                            </span>
                        </button>
                    </div>
                </template>

                <template v-else>
                    <button class="btn btn-link px-0 text-decoration-none mb-2" @click="openAssignment = null">
                        ← Assignments
                    </button>
                    <div class="fw-semibold mb-1">{{ openAssignment.title }}</div>
                    <div class="text-muted small mb-3">Out of {{ openAssignment.points_possible }}</div>

                    <div class="list-group mb-3">
                        <div v-for="s in openAssignment.students" :key="s.membership_id"
                             class="list-group-item d-flex align-items-center gap-3 flex-wrap">
                            <PersonAvatar :avatar="s.contact?.avatar"
                                          :first-name="s.contact?.first_name" :last-name="s.contact?.last_name" :size="34" />
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-semibold small">{{ name(s.contact) }}</span>
                                    <span v-if="s.grade_label" class="badge bg-primary-subtle text-primary-emphasis fw-normal">
                                        {{ s.grade_label }}
                                    </span>
                                </div>
                            </div>
                            <!-- Typing a mark IS "scored". No third button, and the
                                 box is never disabled — the old design made you
                                 press S before you could type the thing S meant. -->
                            <div class="d-flex align-items-center gap-1">
                                <input type="number" min="0" :max="openAssignment.points_possible" step="0.5"
                                       class="form-control form-control-sm text-end" style="width:4.75rem"
                                       :disabled="isExempt(s.membership_id)"
                                       v-model.number="marks_g[s.membership_id].points_earned"
                                       @input="onMarkTyped(s.membership_id)"
                                       placeholder="—">
                                <span class="text-muted small">/ {{ openAssignment.points_possible }}</span>
                            </div>
                            <button type="button" class="btn btn-sm"
                                    :class="marks_g[s.membership_id]?.status === 'missing' ? 'btn-danger' : 'btn-outline-danger'"
                                    @click="toggleMark(s.membership_id, 'missing')">Missing</button>
                            <button type="button" class="btn btn-sm"
                                    :class="marks_g[s.membership_id]?.status === 'excused' ? 'btn-secondary' : 'btn-outline-secondary'"
                                    @click="toggleMark(s.membership_id, 'excused')">Excused</button>
                        </div>
                    </div>

                    <p class="text-muted small mb-2">
                        Type a mark to score a child.
                        <span class="text-danger-emphasis">Missing</span> counts as zero;
                        <span class="fw-semibold">Excused</span> does not count at all.
                        A child you leave blank is simply not marked yet.
                    </p>

                    <div class="d-flex align-items-center gap-2">
                        <button class="btn btn-success btn-sm" :disabled="savingScores" @click="saveScores">
                            {{ savingScores ? 'Saving…' : 'Save all' }}
                        </button>
                        <button class="btn btn-sm btn-link text-danger" @click="withdrawAssignment">Withdraw this work</button>
                        <span v-if="scoresSaved" class="text-success small"><i class="bi bi-check-circle me-1"></i>Saved</span>
                        <span v-if="gradesError" class="text-danger small">{{ gradesError }}</span>
                    </div>
                </template>
            </section>

            <!-- ======================================================== FILES -->
            <section v-else-if="activeTab === 'files'">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-sm">
                                <label class="form-label small text-muted mb-1">Title</label>
                                <input v-model="fileForm.title" type="text" maxlength="200"
                                       class="form-control form-control-sm" placeholder="e.g. Week 3 worksheet">
                            </div>
                            <div class="col-12 col-sm-auto">
                                <label class="form-label small text-muted mb-1">File</label>
                                <input ref="fileInput" type="file" class="form-control form-control-sm"
                                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" @change="onFilePicked">
                            </div>
                        </div>

                        <div class="mt-2">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" id="vis-staff" value="staff"
                                       v-model="fileForm.visibility">
                                <label class="form-check-label small" for="vis-staff">Only me</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" id="vis-fam" value="families"
                                       v-model="fileForm.visibility">
                                <label class="form-check-label small" for="vis-fam">Families in this class</label>
                            </div>
                        </div>

                        <!-- Named at the moment of the choice, with the count in it.
                             The server cannot read inside a PDF; this warning is the
                             only thing standing between a progress report and every
                             guardian in the room. -->
                        <div v-if="fileForm.visibility === 'families'" class="alert alert-warning small py-2 mt-2 mb-0">
                            All {{ students.length }} families in this class will be able to download this file.
                            Do not upload anything that names another child.
                        </div>

                        <div class="d-flex align-items-center gap-2 mt-2">
                            <button class="btn btn-sm btn-success"
                                    :disabled="uploading || !fileForm.file || !fileForm.title.trim()"
                                    @click="uploadFile">
                                {{ uploading ? 'Uploading…' : 'Upload' }}
                            </button>
                            <span v-if="filesError" class="text-danger small">{{ filesError }}</span>
                        </div>
                    </div>
                </div>

                <p v-if="!resources.length" class="text-muted small">No files yet.</p>
                <div v-else class="list-group">
                    <div v-for="r in resources" :key="r.id"
                         class="list-group-item d-flex align-items-center gap-3 flex-wrap">
                        <i class="bi bi-file-earmark fs-5 text-muted"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small">{{ r.title }}</div>
                            <div class="text-muted small">
                                {{ r.original_name }} · {{ Math.max(1, Math.round(r.size_bytes / 1024)) }} KB
                            </div>
                        </div>
                        <span class="badge" :class="r.visibility === 'families' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light text-muted'">
                            {{ r.visibility === 'families' ? 'Shared with families' : 'Only me' }}
                        </span>
                        <button class="btn btn-sm btn-outline-secondary" @click="downloadResource(r)">
                            <i class="bi bi-download"></i>
                        </button>
                        <button class="btn btn-sm btn-link text-danger" @click="deleteResource(r)">Remove</button>
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
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

type TabKey = 'roster' | 'attendance' | 'letters' | 'points' | 'hifz' | 'story' | 'messages'
    | 'lessons' | 'grades' | 'files';

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
    // Second, not last: it is the only tab a teacher touches every single morning.
    { key: 'attendance', label: 'Attendance', icon: 'bi-calendar-check' },
    { key: 'letters', label: 'Letters', icon: 'bi-fonts' },
    { key: 'points', label: 'Points', icon: 'bi-star' },
    // "Hifdh" is the school's own spelling. The KEY stays `hifz` — it is the
    // stored value, the route segment and the API field; only the label moves.
    { key: 'hifz', label: 'Hifdh', icon: 'bi-book' },
    { key: 'story', label: 'Class Story', icon: 'bi-journal-text' },
    { key: 'messages', label: 'Messages', icon: 'bi-chat-dots' },
];

// Behind "More". Newer and less frequent than the seven above — after a term,
// re-decide this split from what the teachers actually tap, not from a guess.
const moreTabs: { key: TabKey; label: string; icon: string }[] = [
    { key: 'lessons', label: 'Lesson Plans', icon: 'bi-calendar3' },
    { key: 'grades', label: 'Grades', icon: 'bi-clipboard-check' },
    { key: 'files', label: 'Files', icon: 'bi-folder2-open' },
];

const activeMoreTab = computed(() => moreTabs.find((t) => t.key === activeTab.value) ?? null);

const moreOpen = ref(false);

// Close on any click that is not inside the dropdown itself (the trigger stops
// propagation), and on Escape.
const closeMore = () => { moreOpen.value = false; };
const closeMoreOnEscape = (e: KeyboardEvent) => { if (e.key === 'Escape') moreOpen.value = false; };

onMounted(() => {
    document.addEventListener('click', closeMore);
    document.addEventListener('keydown', closeMoreOnEscape);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', closeMore);
    document.removeEventListener('keydown', closeMoreOnEscape);
});

const students = computed<any[]>(() => group.value?.students ?? []);
const avatarFor = ref<any>(null);

// ---------- attendance ----------
// Four marks, in the order a teacher reaches for them. `off`/`on` are the
// unselected/selected button classes; colour carries the meaning at a glance
// because the register is read in a doorway, not at a desk.
const ATT_OPTIONS = [
    { value: 'present', short: 'P', label: 'Present', off: 'btn-outline-success', on: 'btn-success' },
    { value: 'absent', short: 'A', label: 'Absent', off: 'btn-outline-danger', on: 'btn-danger' },
    { value: 'late', short: 'L', label: 'Late', off: 'btn-outline-warning', on: 'btn-warning' },
    { value: 'excused', short: 'E', label: 'Excused', off: 'btn-outline-secondary', on: 'btn-secondary' },
];

// The LOCAL calendar day. toISOString() would hand back the UTC day, which is
// yesterday's register for anyone west of Greenwich after 7pm.
const localDay = (d: Date): string =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

const todayIso = localDay(new Date());
const attDate = ref<string>(todayIso);
const marks = ref<Record<number, string>>({});
const attLoading = ref(false);
const attSaving = ref(false);
const attSaved = ref(false);
const attTaken = ref(false);
const attError = ref('');

const markedCount = computed(() => Object.keys(marks.value).length);

const loadAttendance = async () => {
    attLoading.value = true;
    attError.value = '';
    attSaved.value = false;
    try {
        const res = await TeacherApiService.get(`${base.value}/attendance?date=${attDate.value}`);
        const data = res.data?.data ?? {};
        attTaken.value = !!data.taken;
        const next: Record<number, string> = {};
        for (const s of data.students ?? []) {
            // Only a REAL mark seeds the form. An unmarked child stays unmarked,
            // so opening the tab can never silently record a class as present.
            if (s.status) next[s.membership_id] = s.status;
        }
        marks.value = next;
    } catch {
        attError.value = 'Could not load the register.';
    } finally {
        attLoading.value = false;
    }
};

const markAllPresent = () => {
    const next: Record<number, string> = { ...marks.value };
    for (const s of students.value) next[s.membership_id] = 'present';
    marks.value = next;
};

const saveAttendance = async () => {
    attSaving.value = true;
    attError.value = '';
    attSaved.value = false;
    try {
        const payload = {
            session_date: attDate.value,
            marks: Object.entries(marks.value).map(([membership_id, status]) => ({
                membership_id: Number(membership_id),
                status,
            })),
        };
        const res = await TeacherApiService.put(`${base.value}/attendance`, payload);
        attTaken.value = !!res.data?.data?.taken;
        attSaved.value = true;
    } catch (e: any) {
        attError.value = e?.response?.data?.data?.marks?.[0]
            ?? e?.response?.data?.message
            ?? 'Could not save the register.';
    } finally {
        attSaving.value = false;
    }
};

// ---------- lesson plans ----------
const startOfWeek = (d: Date): Date => {
    const c = new Date(d);
    c.setDate(c.getDate() - c.getDay());   // Sunday-first, matching the school week
    return c;
};

const weekStart = ref<string>(localDay(startOfWeek(new Date())));
const planDate = ref<string>(todayIso);
const plans = ref<any[]>([]);
const planForm = ref({ title: '', body: '' });
const planSaving = ref(false);
const planSaved = ref(false);
const planError = ref('');

const weekDays = computed(() => {
    const out: { iso: string; label: string }[] = [];
    const start = new Date(weekStart.value + 'T00:00:00');
    for (let i = 0; i < 7; i++) {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        out.push({
            iso: localDay(d),
            label: d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' }),
        });
    }
    return out;
});

const weekLabel = computed(() => {
    const days = weekDays.value;
    return days.length ? `${days[0].label} — ${days[6].label}` : '';
});

const planFor = (iso: string) => plans.value.find((p) => p.session_date === iso) ?? null;

const loadLessonPlans = async () => {
    planError.value = '';
    try {
        const days = weekDays.value;
        const res = await TeacherApiService.get(
            `${base.value}/lesson-plans?from=${days[0].iso}&to=${days[6].iso}`
        );
        plans.value = res.data?.data?.plans ?? [];
        syncPlanForm();
    } catch {
        planError.value = 'Could not load this week.';
    }
};

/** The form always shows the SELECTED day — never a stale one. */
const syncPlanForm = () => {
    const p = planFor(planDate.value);
    planForm.value = { title: p?.title ?? '', body: p?.body ?? '' };
    planSaved.value = false;
};

watch(planDate, syncPlanForm);
watch(weekStart, loadLessonPlans);

const shiftWeek = (delta: number) => {
    const d = new Date(weekStart.value + 'T00:00:00');
    d.setDate(d.getDate() + delta * 7);
    weekStart.value = localDay(d);
};

const savePlan = async () => {
    planSaving.value = true;
    planError.value = '';
    planSaved.value = false;
    try {
        await TeacherApiService.put(`${base.value}/lesson-plans`, {
            session_date: planDate.value,
            title: planForm.value.title || null,
            body: planForm.value.body,
        });
        planSaved.value = true;
        await loadLessonPlans();
    } catch (e: any) {
        planError.value = e?.response?.data?.data?.session_date?.[0]
            ?? e?.response?.data?.data?.body?.[0]
            ?? 'That plan could not be saved.';
    } finally {
        planSaving.value = false;
    }
};

const deletePlan = async () => {
    try {
        await TeacherApiService.delete(`${base.value}/lesson-plans?date=${planDate.value}`);
        await loadLessonPlans();
    } catch {
        planError.value = 'That plan could not be removed.';
    }
};

// ---------- gradebook ----------
const assignments = ref<any[]>([]);
const assignmentForm = ref({ title: '', points_possible: 10, assigned_on: todayIso });
const creatingAssignment = ref(false);
const openAssignment = ref<any>(null);
const marks_g = ref<Record<number, { status: string | null; points_earned: number | null }>>({});
const savingScores = ref(false);
const scoresSaved = ref(false);
const gradesError = ref('');

const loadAssignments = async () => {
    gradesError.value = '';
    try {
        const res = await TeacherApiService.get(`${base.value}/assignments`);
        assignments.value = res.data?.data ?? [];
    } catch {
        gradesError.value = 'Could not load the gradebook.';
    }
};

const createAssignment = async () => {
    creatingAssignment.value = true;
    gradesError.value = '';
    try {
        await TeacherApiService.post(`${base.value}/assignments`, assignmentForm.value);
        assignmentForm.value = { title: '', points_possible: 10, assigned_on: todayIso };
        await loadAssignments();
    } catch (e: any) {
        gradesError.value = e?.response?.data?.data?.title?.[0]
            ?? e?.response?.data?.data?.points_possible?.[0]
            ?? 'That work could not be added.';
    } finally {
        creatingAssignment.value = false;
    }
};

const openScores = async (a: any) => {
    gradesError.value = '';
    scoresSaved.value = false;
    try {
        const res = await TeacherApiService.get(`${base.value}/assignments/${a.id}`);
        openAssignment.value = res.data?.data ?? null;
        const next: Record<number, any> = {};
        for (const s of openAssignment.value?.students ?? []) {
            // An unmarked child stays unmarked. Seeding a default here would
            // record the whole class the first time anyone tapped Save.
            next[s.membership_id] = { status: s.status ?? null, points_earned: s.points_earned ?? null };
        }
        marks_g.value = next;
    } catch {
        gradesError.value = 'Could not open that work.';
    }
};

/** Missing and Excused both mean "there is no mark", so the box is closed. */
const isExempt = (membershipId: number): boolean => {
    const s = marks_g.value[membershipId]?.status;
    return s === 'missing' || s === 'excused';
};

/**
 * Typing a mark IS scoring — there is no separate button for it.
 *
 * Emptying the box returns the child to UNMARKED rather than to a zero, which
 * is the same distinction the register makes between blank and absent.
 */
const onMarkTyped = (membershipId: number) => {
    const row = marks_g.value[membershipId];
    if (!row) return;
    const v = row.points_earned;
    row.status = (v === null || (v as any) === '' || Number.isNaN(v as any)) ? null : 'scored';
};

/**
 * Missing / Excused, and tapping the lit one again clears the cell.
 *
 * A mis-tap has to be one tap to undo. Without the toggle the only way back to
 * "not marked yet" would be to reload the page.
 */
const toggleMark = (membershipId: number, status: string) => {
    const row = marks_g.value[membershipId] ?? { status: null, points_earned: null };
    row.status = row.status === status ? null : status;
    // The API refuses a mark on anything but `scored`.
    if (row.status !== 'scored') row.points_earned = null;
    marks_g.value[membershipId] = row;
};

const saveScores = async () => {
    if (!openAssignment.value) return;
    savingScores.value = true;
    gradesError.value = '';
    scoresSaved.value = false;
    try {
        const scores = Object.entries(marks_g.value)
            .filter(([, v]) => v.status)
            .map(([membership_id, v]) => ({
                membership_id: Number(membership_id),
                status: v.status,
                ...(v.status === 'scored' ? { points_earned: v.points_earned } : {}),
            }));

        if (!scores.length) { savingScores.value = false; return; }

        const res = await TeacherApiService.put(
            `${base.value}/assignments/${openAssignment.value.id}/scores`, { scores }
        );
        openAssignment.value = res.data?.data ?? openAssignment.value;
        scoresSaved.value = true;
        await loadAssignments();
    } catch (e: any) {
        gradesError.value = e?.response?.data?.data?.scores?.[0] ?? 'Those marks could not be saved.';
    } finally {
        savingScores.value = false;
    }
};

const withdrawAssignment = async () => {
    if (!openAssignment.value) return;
    try {
        await TeacherApiService.delete(`${base.value}/assignments/${openAssignment.value.id}`);
        openAssignment.value = null;
        await loadAssignments();
    } catch {
        gradesError.value = 'That work could not be withdrawn.';
    }
};

// ---------- starting a conversation ----------
const composing = ref(false);
const sendingCompose = ref(false);
const composeError = ref('');
const composeForm = ref<{ about_membership_id: number | null; subject: string; body: string }>({
    about_membership_id: null, subject: '', body: '',
});

const startCompose = () => {
    // Defaults to the FIRST student rather than the whole class: the common case
    // is one family, and a mis-sent class-wide thread cannot be recalled.
    composeForm.value = {
        about_membership_id: students.value[0]?.membership_id ?? null,
        subject: '', body: '',
    };
    composeError.value = '';
    composing.value = true;
};

const createThread = async () => {
    sendingCompose.value = true;
    composeError.value = '';
    try {
        const about = composeForm.value.about_membership_id;
        await TeacherApiService.post(`${base.value}/threads`, {
            subject: composeForm.value.subject,
            // `participant` reaches one child's guardians; `group` reaches every
            // family in the class — the same audience as the class story.
            scope: about ? 'participant' : 'group',
            ...(about ? { about_membership_id: about } : {}),
            body: composeForm.value.body,
        });
        composing.value = false;
        await loadThreads();
    } catch (e: any) {
        composeError.value = e?.response?.data?.message
            ?? e?.response?.data?.data?.subject?.[0]
            ?? 'That message could not be sent.';
    } finally {
        sendingCompose.value = false;
    }
};

// ---------- class files ----------
const resources = ref<any[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const fileForm = ref<{ title: string; visibility: string; file: File | null }>({
    title: '', visibility: 'staff', file: null,
});
const uploading = ref(false);
const filesError = ref('');

const loadResources = async () => {
    filesError.value = '';
    try {
        const res = await TeacherApiService.get(`${base.value}/resources`);
        resources.value = res.data?.data ?? [];
    } catch {
        filesError.value = 'Could not load this class’s files.';
    }
};

const onFilePicked = (e: Event) => {
    fileForm.value.file = (e.target as HTMLInputElement).files?.[0] ?? null;
};

const uploadFile = async () => {
    if (!fileForm.value.file) return;
    uploading.value = true;
    filesError.value = '';
    try {
        const form = new FormData();
        form.append('file', fileForm.value.file);
        form.append('title', fileForm.value.title);
        form.append('visibility', fileForm.value.visibility);

        // postForm, never put/post: this is the one write that must NOT declare
        // application/json — the browser has to write the multipart boundary.
        await TeacherApiService.postForm(`${base.value}/resources`, form);

        fileForm.value = { title: '', visibility: 'staff', file: null };
        if (fileInput.value) fileInput.value.value = '';
        await loadResources();
    } catch (e: any) {
        filesError.value = e?.response?.data?.data?.file?.[0]
            ?? e?.response?.data?.data?.title?.[0]
            ?? 'That file could not be uploaded.';
    } finally {
        uploading.value = false;
    }
};

const downloadResource = async (r: any) => {
    try {
        // A plain <a href> would 401 — the route is bearer-authenticated.
        const url = await TeacherApiService.blobUrl(`${base.value}/resources/${r.id}/download`);
        const a = document.createElement('a');
        a.href = url;
        a.download = r.original_name;
        a.click();
        URL.revokeObjectURL(url);
    } catch {
        filesError.value = 'That file could not be downloaded.';
    }
};

const deleteResource = async (r: any) => {
    try {
        await TeacherApiService.delete(`${base.value}/resources/${r.id}`);
        await loadResources();
    } catch {
        filesError.value = 'That file could not be removed.';
    }
};

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
const letterError = ref('');
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
    letterError.value = '';
    try {
        const res = await TeacherApiService.put(
            `${base.value}/members/${selected.value.membership_id}/letters`,
            { drill_id: drill.id, status: NEXT[drill.status] ?? 'learning' }
        );
        // Marking returns the whole tracker, so totals and tile colour move together.
        tracker.value = res.data?.data ?? tracker.value;
    } catch (e: any) {
        // The tile is deliberately left where it was — a failed write must never
        // lie about progress. But it must SAY SO: this catch was silent, and a
        // teacher tapping a letter that never moved had no way to tell a refusal
        // from a dead button. It hid a 422 through 28 consecutive taps.
        letterError.value = e?.response?.data?.message
            ?? 'That did not save. Check your connection and tap again.';
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
// ONE surah, and the āyāt within it. The API still takes a from/to pair that may
// cross surahs, but a teacher logging today's recitation is almost never crossing
// one — and asking for four numbers to say "Al-Fātiḥah 1-7" made the commonest
// entry the hardest to type. A recitation that genuinely spans two surahs is two
// entries, which is also how it is heard.
const surahs = ref<{ number: number; name: string; ayahs: number }[]>([]);
const hifzForm = ref({
    kind: 'sabak',
    surah: null as number | null,
    from_ayah: null as number | null,
    to_ayah: null as number | null,
    quality: 'good',
});

/** Āyāt in the chosen surah — the ceiling both inputs are bounded by. */
const surahAyahs = computed(() =>
    surahs.value.find((s) => s.number === hifzForm.value.surah)?.ayahs ?? 286);

const ayahCount = computed(() => {
    const { from_ayah: f, to_ayah: t } = hifzForm.value;
    return f && t && t >= f ? t - f + 1 : 0;
});

const hifzValid = computed(() =>
    !!hifzForm.value.surah && ayahCount.value > 0
    && (hifzForm.value.to_ayah ?? 0) <= surahAyahs.value);

const loadSurahs = async () => {
    if (surahs.value.length) return;
    try {
        const res = await TeacherApiService.get(`/api/teacher/masjids/${masjidId.value}/quran-surahs`);
        surahs.value = res.data?.data ?? [];
    } catch {
        // The form still submits by number if the index cannot be reached.
    }
};

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
            // The API's interval may cross surahs; this form deliberately does
            // not, so both ends carry the one chosen surah.
            from_surah: hifzForm.value.surah,
            from_ayah: hifzForm.value.from_ayah,
            to_surah: hifzForm.value.surah,
            to_ayah: hifzForm.value.to_ayah,
            quality: hifzForm.value.quality,
            major_mistakes: 0,
            minor_mistakes: 0,
        });
        // The surah is KEPT: the next entry for this child is usually the next
        // few āyāt of the same one.
        hifzForm.value.from_ayah = hifzForm.value.to_ayah = null;
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
    if (tab === 'attendance') loadAttendance();
    if (tab === 'hifz') loadSurahs();
    if (tab === 'lessons') loadLessonPlans();
    if (tab === 'grades' && !assignments.value.length) loadAssignments();
    if (tab === 'files' && !resources.value.length) loadResources();
    if (tab !== 'grades') { openAssignment.value = null; }
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
