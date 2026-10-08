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
                <!-- Messages is the seventh tab, which a phone scrolls out of sight, so the
                     count is ALSO here, where the class name is, and it goes to the tab. -->
                <button v-if="unreadMessages > 0" type="button" class="badge rounded-pill bg-danger border-0 tc-new-chip"
                        :aria-label="`${unreadSpoken(unreadMessages)}. Open Messages.`"
                        @click="activeTab = 'messages'">{{ newChip(unreadMessages) }}</button>
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
            <div v-if="!classSubjects.enabled.value" class="d-flex align-items-end gap-2 mb-4 border-bottom position-relative">
                <ul class="nav nav-tabs flex-nowrap overflow-auto flex-grow-1 border-0 tc-tabs">
                    <li v-for="t in visibleTabs" :key="t.key" class="nav-item">
                        <button type="button" class="nav-link text-nowrap"
                                :class="{ active: activeTab === t.key }" @click="activeTab = t.key">
                            <i :class="`bi ${t.icon} me-1`"></i>{{ t.label }}
                            <template v-if="t.key === 'messages' && unreadMessages > 0">
                                <span class="badge rounded-pill bg-danger ms-1" aria-hidden="true">{{ unreadPill(unreadMessages) }}</span>
                                <span class="visually-hidden">{{ unreadSpoken(unreadMessages) }}</span>
                            </template>
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
                        <button v-for="t in shownMoreTabs" :key="t.key" type="button"
                                class="btn btn-sm w-100 text-start border-0 rounded-0 px-3 py-2"
                                :class="activeTab === t.key ? 'bg-success-subtle text-success-emphasis fw-semibold' : ''"
                                @click="activeTab = t.key; moreOpen = false">
                            <i :class="`bi ${t.icon} me-2`"></i>{{ t.label }}
                        </button>
                    </div>
                </div>
            </div>

            <ClassNavigation :enabled="classSubjects.enabled.value" :sections="classSubjects.sections.value"
                :currentKey="classSubjects.currentKey.value" :title="classSubjects.title.value"
                :notice="classSubjects.notice.value" :busy="classSubjects.busy.value"
                :href="classSubjects.href" @choose="classSubjects.choose">
            <!-- ============================================ ROSTER (read only) -->
            <section v-if="activeTab === 'roster'">
                <p class="text-muted small">
                    Enrolment is managed by the school office. Tap a student to see their details
                    or change their avatar. You cannot add or remove students here.
                </p>
                <div v-if="!students.length" class="text-muted small">No students on this roster yet.</div>
                <!-- THE WHOLE ROW IS THE BUTTON, with a chevron: the same shape
                     as the Letters rows below, which is how this screen says
                     "this row opens something". One target a thumb cannot miss,
                     rather than a name with a small button beside it.
                     The row shows the name, the grade and the age, and NOTHING
                     ABOUT A PARENT: parents' details are for the school office
                     (the owner's decision, 2026-10-04). The Attendance rows are
                     deliberately not like this: a name there sits beside four
                     mark buttons tapped quickly, and a slip must not open a
                     sheet over the register. -->
                <div v-else class="list-group">
                    <button v-for="s in students" :key="s.membership_id" type="button"
                            class="list-group-item list-group-item-action d-flex align-items-center gap-3 tc-roster-row"
                            @click="sheetFor = s">
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
                                <!-- A whole number from the server, for a student
                                     in a class. Nothing at all when it is unknown. -->
                                <span v-if="ageLabel(s.age)" class="text-muted small">{{ ageLabel(s.age) }}</span>
                            </div>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </button>
                </div>
            </section>

            <!-- ================================================= ATTENDANCE -->
            <section v-else-if="activeTab === 'attendance'">
                <div class="d-flex flex-wrap align-items-end gap-2 mb-3 tc-att-toolbar">
                    <div>
                        <label class="form-label small mb-1">Day</label>
                        <input type="date" class="form-control form-control-sm" style="width: 170px"
                               v-model="attDate" :max="todayIso" @change="loadAttendance" />
                    </div>
                    <div class="flex-grow-1"></div>
                    <button v-if="!attClosed" class="btn btn-sm btn-outline-secondary" :disabled="attLoading || !students.length"
                            @click="markAllPresent">
                        <i class="bi bi-check2-all me-1"></i>All present
                    </button>
                </div>

                <div v-if="attLoading" class="text-muted small">Loading the register…</div>
                <!-- A day the school calendar marks as NO SCHOOL has no register:
                     the server answers no students and refuses a save, so the
                     screen says why instead of showing a register that cannot be
                     kept. -->
                <div v-else-if="attClosed" class="alert alert-secondary d-flex gap-2 align-items-start" role="status">
                    <i class="bi bi-calendar-x fs-5"></i>
                    <div>
                        <div class="fw-semibold">
                            {{ attDate === todayIso ? 'No school today' : `No school on ${attDateLabel}` }}{{ schoolDay?.reason ? ` — ${schoolDay.reason}` : '' }}
                        </div>
                        <div class="small text-muted">
                            The school calendar marks this day as closed, so there is no register to take.
                            Pick another day to see its register.
                        </div>
                    </div>
                </div>
                <div v-else-if="!students.length" class="text-muted small">No students on this roster yet.</div>
                <template v-else>
                    <!-- Advice, never a block: a class can meet off the calendar. -->
                    <div v-if="offDayNote" class="alert alert-light border py-2 small">
                        <i class="bi bi-info-circle me-1"></i>{{ offDayNote }}
                    </div>

                    <div class="small mb-2" :class="attTaken ? 'text-success' : 'text-muted'">
                        <i :class="`bi ${attTaken ? 'bi-check-circle' : 'bi-circle'} me-1`"></i>
                        {{ attTaken ? 'Register taken for this day.' : 'Not taken yet for this day.' }}
                    </div>

                    <div class="list-group mb-3">
                        <div v-for="s in students" :key="s.membership_id"
                             class="list-group-item d-flex align-items-center gap-3 flex-wrap tc-att-row">
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

                    <div class="d-flex align-items-center gap-2 tc-att-save">
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
                <!-- WHICH ALPHABET. Two tracks, never one grid: each has its own
                     drills, its own denominator and its own reading direction,
                     and merging them would draw an alphabet no class teaches. -->
                <div v-if="!classSubjects.enabled.value" class="btn-group btn-group-sm mb-3" role="group" aria-label="Alphabet">
                    <button v-for="a in ALPHABETS" :key="a.id" type="button"
                            class="btn" :class="lettersAlphabet === a.id ? 'btn-success' : 'btn-outline-success'"
                            :disabled="trackerLoading" :aria-pressed="lettersAlphabet === a.id"
                            @click="switchAlphabet(a.id)">
                        {{ a.label }}
                    </button>
                </div>

                <!-- The class's stage, on the track that has one. -->
                <div class="card border-0 bg-light mb-3">
                    <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
                        <div>
                            <div class="fw-semibold">{{ currentStageLabel || alphabetHeading }}</div>
                            <div v-if="currentStageSummary" class="text-muted small">{{ currentStageSummary }}</div>
                        </div>
                        <!-- Hidden on a single-stage track. English is one stage
                             by design, and the endpoint refuses to be told
                             otherwise: the ladder belongs to the qāʿidah, and
                             setting it from an English screen would move the
                             class's ARABIC denominator. -->
                        <div v-if="stageOptions.length > 1" class="d-flex align-items-center gap-2 tc-stage-picker">
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

                <!-- Student list, with each child's progress THROUGH THE STAGE
                     ON SCREEN. The row used to be a bare name: a teacher could
                     not see what the class's stage had cost anybody without
                     opening all twelve children one at a time, while the
                     office's copy of this same tab showed the whole class at a
                     glance. Same endpoint, same denominator. -->
                <div v-if="!selected" class="list-group">
                    <button v-for="s in lettersRoster" :key="s.membership_id" type="button"
                            class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                            @click="openLetters(s)">
                        <PersonAvatar :avatar="s.contact?.avatar"
                                      :first-name="s.contact?.first_name" :last-name="s.contact?.last_name" :size="38" />
                        <div class="flex-grow-1">
                            <div class="fw-semibold small">{{ name(s.contact) }}</div>
                            <!-- Only where the overview answered. An empty bar
                                 drawn while the counts are still in flight says
                                 "nothing mastered", which is a different fact
                                 from "not counted yet". -->
                            <div v-if="s.mastered !== undefined" class="progress mt-1" style="height:6px;">
                                <div class="progress-bar bg-success"
                                     :style="{ width: Math.round((s.completion || 0) * 100) + '%' }"></div>
                            </div>
                        </div>
                        <span v-if="s.mastered !== undefined" class="text-muted small text-nowrap">
                            {{ s.mastered }} / {{ lettersOverview?.total }}
                        </span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </button>
                    <div v-if="!lettersRoster.length" class="text-muted small p-3">No students on this roster yet.</div>
                </div>

                <!-- One child's tracker -->
                <div v-else>
                    <button class="btn btn-link px-0 text-decoration-none mb-2" @click="closeLetters">
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
                            <!-- MARK ALL MASTERED, for a child who already knows
                                 them (BISS teachers, 2026-09-21). Two steps on
                                 purpose: one tap here would change dozens of
                                 cells, so the button only asks, and the
                                 confirmation says what will and will not change. -->
                            <button v-if="(tracker.totals?.mastered ?? 0) < (tracker.totals?.total ?? 0) && !confirmMasterAll"
                                    type="button" class="btn btn-sm btn-outline-success ms-auto"
                                    :disabled="masteringAll" @click="confirmMasterAll = true">
                                Mark all mastered
                            </button>
                        </div>
                        <!-- The two scopes are separate buttons because they are
                             separate decisions, and each says its own number.
                             One button reading "Long Vowels" and writing all
                             five stages is what a teacher reported. -->
                        <div v-if="confirmMasterAll" class="alert alert-warning small" role="alert">
                            <p class="mb-2">
                                What should be marked mastered for {{ name(selected.contact) }}?
                            </p>
                            <p class="mb-2 text-muted">
                                Drills already mastered keep their date, and notes are not touched.
                                You can still move any drill back by tapping it.
                                Letter groups below are never included — each has its own button.
                            </p>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-sm btn-success" :disabled="masteringAll"
                                        @click="masterAll('stage')">
                                    <span v-if="masteringAll" class="spinner-border spinner-border-sm"></span>
                                    <span v-else>
                                        Just {{ tracker.stage?.label ?? 'this stage' }} ({{ stageOwnRemaining }})
                                    </span>
                                </button>
                                <button v-if="everythingRemaining > stageOwnRemaining"
                                        type="button" class="btn btn-sm btn-outline-secondary" :disabled="masteringAll"
                                        @click="masterAll('everything')">
                                    Everything up to here ({{ everythingRemaining }})
                                </button>
                                <button type="button" class="btn btn-sm btn-link text-muted" :disabled="masteringAll"
                                        @click="confirmMasterAll = false">Cancel</button>
                            </div>
                            <p v-if="masterAllError" class="text-danger mt-2 mb-0">{{ masterAllError }}</p>
                        </div>
                        <div v-if="masterAllNote" class="alert alert-success py-2 small">{{ masterAllNote }}</div>

                        <!-- The alphabet's OWN direction, off the payload: Arabic
                             begins at the top right and runs leftward, English
                             does the opposite, and either laid out the other way
                             reads as a jumble rather than as the alphabet a
                             child is learning. -->
                        <!-- English is two runs (Capitals, then Lower case), each
                             with its own count beside the overall total above;
                             Arabic is one run, unlabelled, exactly as it was.
                             The server says which, through `tracker.sets`. -->
                        <div v-for="run in letterRunsOf" :key="run.id" class="mb-3">
                            <div v-if="run.label" class="d-flex justify-content-between align-items-baseline small mb-1" dir="ltr">
                                <span class="fw-semibold">{{ run.label }}</span>
                                <span class="text-muted">{{ run.mastered }} / {{ run.total }}</span>
                            </div>
                            <div class="d-flex flex-wrap gap-2" :dir="lettersDir">
                                <button v-for="tile in run.tiles" :key="tile.key" type="button"
                                        class="letter-tile" :class="`letter-tile--${tile.status}`"
                                        :title="tile.title"
                                        @click="openTile = toggledTileKey(openTile, tile)">
                                    <span class="letter-tile__glyph">{{ tile.text }}</span>
                                    <span class="letter-tile__name">{{ tile.name }}</span>
                                </button>
                            </div>
                        </div>

                        <div v-if="letter" class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-baseline mb-2">
                                    <h6 class="mb-0">{{ letterHeading }}</h6>
                                    <button class="btn-close" @click="openTile = null"></button>
                                </div>

                                <!-- An Arabic word BEGINS at the right, so the
                                     initial form sits on the right; an English
                                     one does not. Same payload, same question. -->
                                <div v-if="letter.positions?.length" class="d-flex gap-2 mb-3" :dir="lettersDir">
                                    <div v-for="p in letter.positions" :key="p.id" class="shape-box">
                                        <div class="shape-box__glyph">{{ p.text }}</div>
                                        <div class="shape-box__label">{{ positionLabel(p.id) }}</div>
                                    </div>
                                </div>

                                <!-- The row was one big button, which is why the
                                     note lives beside it and not inside it: a
                                     button cannot contain a button, and anything
                                     inside the tap target would mean reaching
                                     for the note and advancing a child's status
                                     by accident. Marking and writing are
                                     different acts and stay different targets. -->
                                <div class="list-group" :dir="lettersDir">
                                    <div v-for="d in letter.drills" :key="d.id"
                                         class="list-group-item p-0" :class="`drill--${d.status}`">
                                        <div class="d-flex align-items-center">
                                            <button type="button"
                                                    class="btn btn-link text-reset text-decoration-none flex-grow-1 d-flex align-items-center gap-3 px-3 py-2"
                                                    :disabled="marking === d.id" @click="advance(d)">
                                                <span class="drill__glyph">{{ d.text }}</span>
                                                <span class="flex-grow-1 small" dir="ltr" style="text-align:start;">
                                                    {{ d.label }}
                                                    <span v-if="d.sound" class="text-muted">· sounds like “{{ d.sound }}”</span>
                                                </span>
                                                <span class="badge" :class="badgeClass(d.status)">{{ statusLabel(d.status) }}</span>
                                            </button>
                                            <button type="button" class="btn btn-link px-3 py-2"
                                                    :class="d.note ? 'text-success' : 'text-muted'"
                                                    :aria-expanded="openDrillNote === d.id"
                                                    :title="d.note ? 'Edit the note on this drill' : 'Write a note about this drill'"
                                                    @click="toggleDrillNote(d)">
                                                <i class="bi" :class="d.note ? 'bi-chat-left-text-fill' : 'bi-chat-left-text'"></i>
                                                <span class="visually-hidden">Note on {{ d.label }}</span>
                                            </button>
                                        </div>

                                        <!-- Shown whenever there IS one, not only
                                             while editing. The note existed for a
                                             day as a field that accepted writing
                                             and displayed nothing; a teacher had
                                             no way to see what she had already
                                             said about this child. -->
                                        <div v-if="d.note && openDrillNote !== d.id"
                                             class="px-3 pb-2 small text-muted fst-italic"
                                             dir="ltr" style="text-align:start;">
                                            {{ d.note }}
                                        </div>

                                        <div v-if="openDrillNote === d.id" class="px-3 pb-3" dir="ltr" style="text-align:start;">
                                            <textarea class="form-control form-control-sm" rows="2"
                                                      v-model="drillNoteDraft" :maxlength="arabicNoteMax"
                                                      :disabled="savingDrillNote"
                                                      placeholder="e.g. confuses this with sīn when she is tired"></textarea>
                                            <div class="d-flex align-items-center gap-2 mt-2">
                                                <button class="btn btn-sm btn-success" :disabled="savingDrillNote" @click="saveDrillNote(d)">
                                                    <span v-if="savingDrillNote" class="spinner-border spinner-border-sm"></span>
                                                    <span v-else>Save note</span>
                                                </button>
                                                <button class="btn btn-sm btn-link text-muted" :disabled="savingDrillNote"
                                                        @click="openDrillNote = null">Cancel</button>
                                                <!-- Clearing is deliberate and says so. An
                                                     empty box saved by accident would erase a
                                                     sentence about a child without a word. -->
                                                <button v-if="d.note" class="btn btn-sm btn-link text-danger ms-auto"
                                                        :disabled="savingDrillNote" @click="clearDrillNote(d)">Remove note</button>
                                            </div>
                                            <p v-if="drillNoteError" class="text-danger small mt-2 mb-0">{{ drillNoteError }}</p>
                                            <div class="form-text">{{ drillNoteDraft.length }} / {{ arabicNoteMax }}</div>
                                        </div>
                                    </div>
                                </div>
                                <p v-if="letterError" class="text-danger small mt-2 mb-0">{{ letterError }}</p>
                                <p class="text-muted small mt-2 mb-0">Tap a drill to move it: Not started → Learning → Mastered. The speech bubble writes a note about that drill.</p>
                            </div>
                        </div>

                        <!-- ---------------------------------- LETTER GROUPS --
                             How a letter is SOUNDED, as against which letter it
                             is. These are not stages and are deliberately not on
                             the ladder: غ and خ are throat letters AND heavy
                             letters, so one letter would have to sit in two
                             stages at once.

                             Each group carries its OWN total, and none of them
                             counts toward the stage progress above. Adding
                             twenty-eight drills to that denominator would have
                             moved every existing class's bar backwards for a
                             change nobody asked them about.

                             Absent on a track that has no such teaching: the
                             English alphabet answers with an empty list and
                             nothing below draws. -->
                        <div v-if="tracker.groups?.length" class="mt-4">
                            <h6 class="text-muted small text-uppercase mb-2">How the letters sound</h6>

                            <div v-for="g in tracker.groups" :key="g.id" class="card border-0 shadow-sm mb-3">
                                <div class="card-body">
                                    <div class="d-flex align-items-baseline gap-2 mb-1">
                                        <h6 class="mb-0">{{ g.label }}</h6>
                                        <span v-if="g.arabic_name" class="text-muted" dir="rtl">{{ g.arabic_name }}</span>
                                        <span class="ms-auto text-muted small text-nowrap">
                                            {{ g.totals?.mastered ?? 0 }} / {{ g.totals?.total ?? 0 }}
                                        </span>
                                    </div>
                                    <p class="text-muted small mb-2">{{ g.summary }}</p>

                                    <div class="list-group mb-2" :dir="lettersDir">
                                        <div v-for="d in g.drills" :key="d.id"
                                             class="list-group-item p-0" :class="`drill--${d.status}`">
                                            <div class="d-flex align-items-center">
                                                <button type="button"
                                                        class="btn btn-link text-reset text-decoration-none flex-grow-1 d-flex align-items-center gap-3 px-3 py-2"
                                                        :disabled="marking === d.id" @click="advance(d)">
                                                    <span class="drill__glyph">{{ d.text }}</span>
                                                    <span class="flex-grow-1 small" dir="ltr" style="text-align:start;">{{ d.label }}</span>
                                                    <span class="badge" :class="badgeClass(d.status)">{{ statusLabel(d.status) }}</span>
                                                </button>
                                                <button type="button" class="btn btn-link px-3 py-2"
                                                        :class="d.note ? 'text-success' : 'text-muted'"
                                                        :aria-expanded="openDrillNote === d.id"
                                                        :title="d.note ? 'Edit the note on this drill' : 'Write a note about this drill'"
                                                        @click="toggleDrillNote(d)">
                                                    <i class="bi" :class="d.note ? 'bi-chat-left-text-fill' : 'bi-chat-left-text'"></i>
                                                    <span class="visually-hidden">Note on {{ d.label }}</span>
                                                </button>
                                            </div>

                                            <div v-if="d.note && openDrillNote !== d.id"
                                                 class="px-3 pb-2 small text-muted fst-italic"
                                                 dir="ltr" style="text-align:start;">
                                                {{ d.note }}
                                            </div>

                                            <div v-if="openDrillNote === d.id" class="px-3 pb-3" dir="ltr" style="text-align:start;">
                                                <textarea class="form-control form-control-sm" rows="2"
                                                          v-model="drillNoteDraft" :maxlength="arabicNoteMax"
                                                          :disabled="savingDrillNote"></textarea>
                                                <div class="d-flex align-items-center gap-2 mt-2">
                                                    <button class="btn btn-sm btn-success" :disabled="savingDrillNote" @click="saveDrillNote(d)">
                                                        <span v-if="savingDrillNote" class="spinner-border spinner-border-sm"></span>
                                                        <span v-else>Save note</span>
                                                    </button>
                                                    <button class="btn btn-sm btn-link text-muted" :disabled="savingDrillNote"
                                                            @click="openDrillNote = null">Cancel</button>
                                                    <button v-if="d.note" class="btn btn-sm btn-link text-danger ms-auto"
                                                            :disabled="savingDrillNote" @click="clearDrillNote(d)">Remove note</button>
                                                </div>
                                                <p v-if="drillNoteError" class="text-danger small mt-2 mb-0">{{ drillNoteError }}</p>
                                                <div class="form-text">{{ drillNoteDraft.length }} / {{ arabicNoteMax }}</div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Where ر, ل and ا went. A group that
                                         quietly omits three letters reads as a
                                         bug to anyone who knows the alphabet. -->
                                    <p v-if="g.note" class="text-muted small fst-italic mb-2">{{ g.note }}</p>

                                    <div v-if="confirmGroup !== g.id">
                                        <button v-if="(g.totals?.mastered ?? 0) < (g.totals?.total ?? 0)"
                                                type="button" class="btn btn-sm btn-outline-success"
                                                :disabled="masteringGroup !== null" @click="confirmGroup = g.id">
                                            Mark all {{ g.label.toLowerCase() }} mastered
                                        </button>
                                    </div>
                                    <div v-else class="alert alert-warning small mb-0" role="alert">
                                        <p class="mb-2">
                                            Mark the {{ (g.totals?.total ?? 0) - (g.totals?.mastered ?? 0) }} remaining
                                            {{ g.label.toLowerCase() }} mastered for {{ name(selected.contact) }}?
                                            This touches {{ g.label.toLowerCase() }} only — nothing else on this screen changes.
                                        </p>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-success"
                                                    :disabled="masteringGroup !== null" @click="masterGroup(g)">
                                                <span v-if="masteringGroup === g.id" class="spinner-border spinner-border-sm"></span>
                                                <span v-else>Yes, mark them mastered</span>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-link text-muted"
                                                    :disabled="masteringGroup !== null" @click="confirmGroup = null">Cancel</button>
                                        </div>
                                        <p v-if="groupError" class="text-danger mt-2 mb-0">{{ groupError }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ------------------------------------- DAILY NOTE --
                             How the child did TODAY, which belongs to no single
                             letter. It sits under the tiles rather than in a tab
                             of its own because it is written at the end of the
                             same sitting the marks are made in, and a teacher
                             who has to go and find it writes it once and then
                             never again.

                             One child, one day — the owner's decision. It
                             carries no status: the ask was for notes on
                             progress, not a grade for it.

                             ARABIC ONLY, and shown only on that track. The
                             column, the endpoint and the owner's request are all
                             about the qāʿidah; the same panel over the English
                             tiles would offer to file a note about A–Z work into
                             a record headed "Arabic", and nothing downstream
                             could tell the two apart afterwards. -->
                        <div v-if="lettersAlphabet === 'arabic'" class="card border-0 shadow-sm mt-3">
                            <div class="card-body">
                                <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-2">
                                    <h6 class="mb-0">Daily Arabic note</h6>
                                    <span class="text-muted small">About the lesson, not about one letter.</span>
                                </div>

                                <div class="row g-2 align-items-end">
                                    <div class="col-12 col-sm-auto">
                                        <label class="form-label small text-muted mb-1">Day</label>
                                        <!-- Bounded at today, as the endpoint is: a
                                             note about a lesson that has not happened
                                             is a mis-keyed year every time, and it
                                             would sit at the top of the child's
                                             history until somebody noticed. -->
                                        <input type="date" class="form-control form-control-sm"
                                               v-model="dailyNoteDate" :max="today" :disabled="savingDailyNote" />
                                    </div>
                                    <div class="col-12 col-sm">
                                        <label class="form-label small text-muted mb-1">
                                            {{ dailyNoteExisting ? 'Correcting what was written for this day' : 'Note' }}
                                        </label>
                                        <textarea class="form-control form-control-sm" rows="2"
                                                  v-model="dailyNoteDraft" :maxlength="arabicDailyNoteMax"
                                                  :disabled="savingDailyNote"
                                                  placeholder="e.g. read the first line unaided, tired by the end"></textarea>
                                    </div>
                                    <div class="col-auto">
                                        <button class="btn btn-sm btn-success"
                                                :disabled="savingDailyNote || !dailyNoteDraft.trim()"
                                                @click="saveDailyNote">
                                            <span v-if="savingDailyNote" class="spinner-border spinner-border-sm"></span>
                                            <span v-else>{{ dailyNoteExisting ? 'Update' : 'Save' }}</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- The teacher is TOLD she is about to correct a day
                                     rather than add one. The endpoint upserts, so
                                     without this a second note about Thursday
                                     silently replaces the first and nothing on the
                                     screen ever said so. -->
                                <p v-if="dailyNoteExisting" class="form-text mb-0">
                                    This day already has a note, shown above. Saving replaces it.
                                </p>
                                <p v-if="dailyNoteError" class="text-danger small mt-2 mb-0">{{ dailyNoteError }}</p>

                                <hr class="my-3" />

                                <div v-if="dailyNotesLoading" class="text-center py-2">
                                    <span class="spinner-border spinner-border-sm text-success"></span>
                                </div>
                                <div v-else-if="dailyNotesFailed" class="text-muted small">
                                    {{ dailyNotesFailed }} This is not the same as there being none —
                                    reopen the student to try again.
                                </div>
                                <div v-else-if="!dailyNotes.length" class="text-muted small">
                                    No daily notes for this student yet.
                                </div>
                                <div v-else class="list-group list-group-flush">
                                    <div v-for="n in dailyNotes" :key="n.id"
                                         class="list-group-item px-0 d-flex align-items-start gap-3">
                                        <div class="flex-grow-1">
                                            <div class="small fw-semibold">{{ longDate(n.session_date) }}</div>
                                            <div class="small" style="white-space: pre-wrap;">{{ n.note }}</div>
                                            <div v-if="n.marked_by" class="text-muted small">— {{ n.marked_by }}</div>
                                        </div>
                                        <button class="btn btn-sm btn-link text-muted px-1"
                                                :disabled="savingDailyNote" title="Edit this day"
                                                @click="editDailyNote(n)">
                                            <i class="bi bi-pencil"></i>
                                            <span class="visually-hidden">Edit the note for {{ longDate(n.session_date) }}</span>
                                        </button>
                                        <button class="btn btn-sm btn-link text-danger px-1"
                                                :disabled="deletingDailyNote === n.id" title="Remove this day's note"
                                                @click="deleteDailyNote(n)">
                                            <i class="bi bi-trash"></i>
                                            <span class="visually-hidden">Remove the note for {{ longDate(n.session_date) }}</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            <!-- ==================================================== POINTS -->
            <section v-else-if="activeTab === 'points'">
                <p class="text-muted small">Behaviour points for one student at a time.</p>

                <!-- THE WEEKLY RESET (T-003.2, teachers can opt in). A VIEW
                     choice: nothing is deleted or revoked either way, the
                     running history stays, and switching it off gives the
                     running total straight back. It belongs to the CLASS, not to
                     this teacher (a family sees one number for their child), and
                     the screen says so here instead of letting a teacher find out
                     from a colleague. -->
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" role="switch" id="points-weekly"
                           :checked="pointsWeekly" :disabled="savingPeriod" @change="setPointsPeriod($event.target as HTMLInputElement)">
                    <label class="form-check-label small fw-semibold" for="points-weekly">Start each week fresh</label>
                </div>
                <p class="text-muted small mb-3">
                    Points show one week at a time (Sunday to Saturday), with every earlier week kept in the history.
                    Nothing is deleted, and you can switch this off at any time.
                    This applies to every teacher of this class.
                </p>
                <p v-if="periodError" class="text-danger small">{{ periodError }}</p>

                <label class="form-label small text-muted">Student</label>
                <select class="form-select form-select-sm mb-3" style="max-width: 22rem"
                        v-model="pointsMembership" @change="loadAwards">
                    <option value="">Choose a student…</option>
                    <option v-for="s in students" :key="s.membership_id" :value="s.membership_id">
                        {{ name(s.contact) }}
                    </option>
                </select>

                <!-- RUNNING TOTALS (BISS teachers, 2026-09-21). The net of every
                     award, corrections included — the same number the family
                     sees for their own child. In ROSTER order and never sorted
                     by points: this is the teacher's overview, not a leaderboard
                     (.claude/rules/groups.md). -->
                <div v-if="pointsTotals" class="card border-0 bg-light mb-3">
                    <div class="card-body py-2">
                        <!-- The week in view, with its neighbours. The class's
                             own choice decides which figure leads (a weekly class
                             leads with the week), but both are always shown. -->
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0" aria-label="Previous week"
                                    @click="loadPointsTotals(pointsTotals.week?.previous)"><i class="bi bi-chevron-left"></i></button>
                            <span class="small fw-semibold">
                                {{ pointsTotals.week?.is_current ? 'This week' : 'Week' }}
                                <span class="text-muted fw-normal">· {{ pointsWeekLabel }}</span>
                            </span>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0" aria-label="Next week"
                                    :disabled="pointsTotals.week?.is_current" @click="loadPointsTotals(pointsTotals.week?.next)"><i class="bi bi-chevron-right"></i></button>
                        </div>
                        <div v-if="pointsMembership && selectedPointsTotal" class="d-flex justify-content-between align-items-baseline">
                            <span class="small fw-semibold">{{ name(selectedPointsTotal.contact) }}’s {{ pointsWeekly ? 'week' : 'total' }}</span>
                            <span class="fw-semibold">{{ signedPoints(selectedHeadline.points) }}</span>
                        </div>
                        <div v-if="pointsMembership && selectedPointsTotal" class="d-flex justify-content-between align-items-baseline small text-muted">
                            <span>{{ pointsWeekly ? 'All weeks' : 'This week' }}</span>
                            <span>{{ signedPoints(selectedHeadline.other.points) }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline small text-muted">
                            <span>Whole class</span>
                            <span>{{ signedPoints(classHeadline.points) }}</span>
                        </div>
                        <details class="mt-1">
                            <summary class="small text-primary" style="cursor:pointer">Each student’s {{ pointsWeekly ? 'week' : 'total' }}</summary>
                            <ul class="list-unstyled mb-0 mt-1">
                                <li v-for="t in pointsTotals.students" :key="t.membership_id"
                                    class="d-flex justify-content-between small py-1 border-bottom">
                                    <button type="button" class="btn btn-link btn-sm p-0 text-reset text-decoration-none"
                                            @click="pointsMembership = t.membership_id; loadAwards()">
                                        {{ name(t.contact) }}
                                    </button>
                                    <span>{{ signedPoints(pointsHeadline(pointsPeriod, t).points) }}</span>
                                </li>
                            </ul>
                        </details>
                    </div>
                </div>
                <p v-else-if="pointsTotalsFailed" class="text-muted small">
                    The running totals could not be loaded. This is not the same as everyone having none.
                </p>

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
                                <!-- Blank means "whatever this skill is normally worth".
                                     A number here overrides it for THIS award only, so
                                     recognising something exceptional never means editing
                                     the whole school's vocabulary. -->
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Points</label>
                                    <input type="number" class="form-control form-control-sm" style="width:6rem"
                                           v-model.number="awardPoints"
                                           :placeholder="String(selectedSkillPoints ?? 'default')">
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
                        No behaviour skills are defined for this school yet. Add the first one below.
                    </div>

                    <!-- ADD YOUR OWN. What a school chooses to notice about a child is a
                         teaching decision, and the person holding it is the teacher in the
                         room — Al-Razi ran a whole term on one skill because adding another
                         meant asking the office. Collapsed by default so it never competes
                         with the thing a teacher opened this tab to do. -->
                    <details class="mb-3">
                        <summary class="small text-primary" style="cursor:pointer">Add your own</summary>
                        <div class="card border mt-2">
                            <div class="card-body">
                                <div class="row g-2 align-items-end">
                                    <div class="col-12 col-sm">
                                        <label class="form-label small text-muted mb-1">What are you recognising?</label>
                                        <input type="text" maxlength="80" class="form-control form-control-sm"
                                               v-model.trim="newSkill.label" placeholder="e.g. Helped without being asked">
                                    </div>
                                    <div class="col-6 col-sm-auto">
                                        <label class="form-label small text-muted mb-1">Kind</label>
                                        <select class="form-select form-select-sm" style="width:9rem" v-model="newSkill.polarity">
                                            <option value="positive">Encouragement</option>
                                            <option value="negative">Correction</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-sm-auto">
                                        <label class="form-label small text-muted mb-1">Worth</label>
                                        <input type="number" class="form-control form-control-sm" style="width:6rem"
                                               v-model.number="newSkill.default_points">
                                    </div>
                                    <div class="col-auto">
                                        <button class="btn btn-sm btn-outline-success"
                                                :disabled="addingSkill || !newSkill.label"
                                                @click="createSkill">
                                            {{ addingSkill ? 'Adding…' : 'Add' }}
                                        </button>
                                    </div>
                                </div>
                                <p class="text-muted small mt-2 mb-0">
                                    This is added for the whole school, so the other teachers will see it too.
                                    Renaming or retiring one is still the office's to do.
                                </p>
                                <p v-if="skillError" class="text-danger small mt-1 mb-0">{{ skillError }}</p>
                            </div>
                        </div>
                    </details>

                    <div v-if="awardError" class="alert alert-danger small py-2">{{ awardError }}</div>

                    <div v-if="awardsLoading" class="text-center py-3"><span class="spinner-border spinner-border-sm text-success"></span></div>
                    <p v-else-if="!awards.length" class="text-muted small">Nothing recorded yet.</p>
                    <ul v-else class="list-unstyled mb-0">
                        <li v-for="a in awards" :key="a.id" class="d-flex gap-2 align-items-baseline py-1 border-bottom">
                            <span class="badge"
                                  :class="a.polarity === 'negative' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success-emphasis'">
                                {{ awardPointsLabel(a) }}
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
                <!-- Held while a note is saving or a line is being copied: both
                     answer to the list that is on screen, and a change of student
                     under them would land the answer on another child's lines. -->
                <select class="form-select form-select-sm mb-3" style="max-width: 22rem"
                        v-model="hifzMembership" :disabled="hifzBusy || !!hifzEditing" @change="loadHifz">
                    <option value="">Choose a student…</option>
                    <option v-for="s in students" :key="s.membership_id" :value="s.membership_id">
                        {{ name(s.contact) }}
                    </option>
                </select>

                <template v-if="hifzMembership">
                    <div id="hifz-form" class="card border mb-3" :class="{ 'border-success': hifzEditing }">
                        <div class="card-body">
                            <!-- Changing a line that is already recorded (owner,
                                 2026-10-07: "edit the date as well or really all
                                 aspects of their entry"). The form below is the
                                 line; saving corrects it in place on the server,
                                 which keeps the old version as a struck copy. -->
                            <div v-if="hifzEditing" class="alert alert-success py-2 px-3 small mb-3 hifz-editing" role="status">
                                <div class="fw-semibold">Changing this line</div>
                                <div class="text-capitalize">{{ hifzLine(hifzEditing) }}</div>
                                <div class="text-muted">Saving corrects this line. The earlier version is kept in the school's records.</div>
                            </div>
                            <div class="row g-2 align-items-end">
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Type</label>
                                    <!-- Plain English only. The STORED values are still
                                         sabak/sabqi/manzil; this is presentation.
                                         Not "New lesson" for sabak, natural as that
                                         reads, because there is now a Lesson Plans tab
                                         and the two would be read as the same thing. -->
                                    <select class="form-select form-select-sm" style="min-width:12rem" v-model="hifzForm.kind">
                                        <option value="sabak">{{ hifzKindLabel('sabak') }}</option>
                                        <option value="sabqi">{{ hifzKindLabel('sabqi') }}</option>
                                        <option value="manzil">{{ hifzKindLabel('manzil') }}</option>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-auto">
                                    <label class="form-label small text-muted mb-1" for="hifz-surah">Surah</label>
                                    <!-- Type-to-find, by number or part of the name.
                                         It was a plain list of 114 to scroll through
                                         for every recitation. -->
                                    <SurahPicker input-id="hifz-surah" style="min-width:14rem"
                                                 :surahs="surahs" v-model="hifzForm.surah" />
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Ayahs</label>
                                    <div class="d-flex align-items-center gap-1">
                                        <input type="number" min="1" :max="surahAyahs" class="form-control form-control-sm"
                                               style="width:4.5rem" v-model.number="hifzForm.from_ayah" placeholder="from"
                                               :disabled="hifzForm.whole_surah">
                                        <span class="text-muted small">to</span>
                                        <input type="number" min="1" :max="surahAyahs" class="form-control form-control-sm"
                                               style="width:4.5rem" v-model.number="hifzForm.to_ayah" placeholder="to"
                                               :disabled="hifzForm.whole_surah">
                                    </div>
                                    <!-- The whole surah, without knowing how many
                                         āyāt it has: the SERVER fills in first to
                                         last from its own index, so the record is
                                         an ordinary full range. -->
                                    <div class="form-check form-check-inline small mt-1">
                                        <input id="hifz-whole-surah" type="checkbox" class="form-check-input"
                                               v-model="hifzForm.whole_surah">
                                        <label for="hifz-whole-surah" class="form-check-label">Whole surah</label>
                                    </div>
                                </div>
                                <div v-if="ayahCount" class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">&nbsp;</label>
                                    <div class="small text-success fw-semibold pt-1">{{ ayahCount }} āyah{{ ayahCount === 1 ? '' : 's' }}</div>
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Quality</label>
                                    <select class="form-select form-select-sm" v-model="hifzForm.quality">
                                        <option v-for="q in hifzQualities" :key="q" :value="q">{{ hifzQualityLabel(q) }}</option>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Heard on</label>
                                    <!-- `max` is today: the API refuses a future
                                         recitation, and a date that cannot be
                                         valid should not be offerable. -->
                                    <input type="date" class="form-control form-control-sm"
                                           v-model="hifzForm.recited_on" :max="todayIso"
                                           :placeholder="todayIso" />
                                </div>
                                <div class="col-12 col-sm">
                                    <label class="form-label small text-muted mb-1">Note <span class="text-muted">(optional)</span></label>
                                    <input type="text" class="form-control form-control-sm"
                                           v-model="hifzForm.note" :maxlength="hifzNoteMax"
                                           placeholder="e.g. struggled with the waqf on ayah 12" />
                                </div>
                                <div class="col-auto d-flex align-items-center gap-2">
                                    <template v-if="hifzEditing">
                                        <button class="btn btn-sm btn-success" :disabled="hifzBusy || !hifzValid" @click="saveHifzEdit">
                                            <span v-if="recordingHifz" class="spinner-border spinner-border-sm"></span>
                                            <span v-else>Save changes</span>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-link text-muted" :disabled="hifzBusy"
                                                @click="cancelHifzEdit">Cancel</button>
                                    </template>
                                    <button v-else class="btn btn-sm btn-success" :disabled="hifzBusy || !hifzValid" @click="recordHifz">
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
                        <li v-for="h in hifz" :key="h.id" class="py-1 border-bottom small">
                            <div class="d-flex flex-wrap gap-2 align-items-baseline">
                                <span class="text-capitalize flex-grow-1">
                                    <template v-if="h.whole_surah">{{ hifzKindLabel(h.kind) }}: all of {{ h.from?.surah_name ?? `Surah ${h.from?.surah}` }}</template>
                                    <template v-else>{{ hifzKindLabel(h.kind) }}: {{ ayah(h.from) }} &rarr; {{ ayah(h.to) }}</template>
                                    <span class="text-muted">· {{ hifzQualityLabel(h.quality) }} · {{ hifzDayLabel(h.recited_at, undefined, h.created_at) }}</span>
                                    <span v-if="hifzEditing && hifzEditing.id === h.id" class="badge bg-success-subtle text-success-emphasis fw-normal ms-1">being changed above</span>
                                </span>
                                <!-- One group, so on a phone the three actions move
                                     under the line together instead of one by one. -->
                                <span class="d-flex gap-2 ms-auto align-items-baseline">
                                <!-- The note is the one thing on a line that is
                                     changed in place. What was heard (portion,
                                     type, quality, day) is still corrected by
                                     Remove and recording again. -->
                                <button type="button" class="btn btn-sm btn-link p-0 text-nowrap"
                                        :class="h.note ? 'text-success' : 'text-muted'"
                                        :aria-expanded="openHifzNote === h.id" :disabled="hifzBusy || !!hifzEditing"
                                        @click="toggleHifzNote(h)">{{ h.note ? 'Edit note' : 'Add note' }}</button>
                                <!-- Everything else about the line: the day, the
                                     surah and āyāt, the type, the quality. Not on a
                                     line that runs across two surahs, which the form
                                     above cannot hold (it records one surah). -->
                                <button v-if="hifzEditable(h)" type="button" class="btn btn-sm btn-link p-0 text-nowrap"
                                        :disabled="hifzBusy || !!hifzEditing"
                                        title="Change the day, the surah and ayahs, the type, the quality or the note"
                                        @click="startHifzEdit(h)">Edit entry</button>
                                <!-- A note written for one child is often the note
                                     for the group that recited with her (owner,
                                     2026-10-07). Offered on a line that HAS a note:
                                     that is what there is to copy. -->
                                <button v-if="h.note && hifzClassmates.length" type="button"
                                        class="btn btn-sm btn-link p-0 text-nowrap"
                                        :aria-expanded="openHifzCopy === h.id" :disabled="hifzBusy || !!hifzEditing"
                                        title="Copy this line and its note to other students"
                                        @click="toggleHifzCopy(h)">Copy to students</button>
                                <button class="btn btn-sm btn-link text-danger p-0" :disabled="removingHifz === h.id || hifzBusy || !!hifzEditing"
                                        @click="removeHifz(h)">Remove</button>
                                </span>
                            </div>
                            <!-- Shown whenever there IS one. The form above has
                                 taken a note since this tab shipped and this list
                                 never displayed it, so a teacher could not find
                                 what she had written about a child's recitation
                                 (the same gap the drill notes on the Letters tab
                                 once had). Outside the capitalised line: these
                                 are her words, shown as she typed them. -->
                            <div v-if="h.note && openHifzNote !== h.id" class="text-muted fst-italic hifz-note" dir="auto" style="white-space: pre-wrap;">
                                <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i><span class="visually-hidden">Note: </span>{{ h.note }}
                            </div>

                            <div v-if="openHifzNote === h.id" class="mt-1 mb-2 hifz-note-editor">
                                <textarea class="form-control form-control-sm" rows="2" dir="auto"
                                          v-model="hifzNoteDraft" :maxlength="hifzNoteMax"
                                          :disabled="savingHifzNote" aria-label="Note on this recitation"
                                          placeholder="e.g. struggled with the waqf on ayah 12"></textarea>
                                <div class="d-flex align-items-center gap-2 mt-2">
                                    <button type="button" class="btn btn-sm btn-success" :disabled="savingHifzNote" @click="saveHifzNote(h)">
                                        <span v-if="savingHifzNote" class="spinner-border spinner-border-sm"></span>
                                        <span v-else>Save note</span>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-link text-muted" :disabled="savingHifzNote"
                                            @click="openHifzNote = null">Cancel</button>
                                    <!-- Clearing is deliberate and says so, as on the
                                         Letters tab: an empty box saved by accident
                                         would erase a sentence about a child. -->
                                    <button v-if="h.note" type="button" class="btn btn-sm btn-link text-danger ms-auto"
                                            :disabled="savingHifzNote" @click="saveHifzNote(h, '')">Remove note</button>
                                </div>
                                <p v-if="hifzNoteError" class="text-danger small mt-2 mb-0">{{ hifzNoteError }}</p>
                                <div class="form-text">{{ hifzNoteDraft.length }} / {{ hifzNoteMax }} · This student's family can read this note.</div>
                            </div>

                            <!-- Copy to other students. A note lives on a line, so
                                 each chosen student gets the LINE with the note: the
                                 panel says exactly that, and says when the line is
                                 one that moves a child forward. -->
                            <div v-if="openHifzCopy === h.id" class="mt-1 mb-2 border rounded-3 px-2 py-2 bg-body-tertiary hifz-copy">
                                <div class="fw-semibold mb-1">Copy this line and its note to other students</div>
                                <div class="d-flex flex-wrap column-gap-3 row-gap-1 mb-2">
                                    <div v-for="s in hifzClassmates" :key="s.membership_id" class="form-check">
                                        <input :id="`hifz-copy-${h.id}-${s.membership_id}`" type="checkbox" class="form-check-input"
                                               :checked="hifzCopyTo.includes(s.membership_id)" :disabled="copyingHifz"
                                               @change="toggleHifzCopyTo(s.membership_id)">
                                        <label :for="`hifz-copy-${h.id}-${s.membership_id}`" class="form-check-label">{{ name(s.contact) }}</label>
                                    </div>
                                </div>
                                <p class="text-muted mb-2">
                                    Each student you choose gets their own line: the same portion, type, quality and day, with this note.
                                    You can change the note or remove the line on their log.
                                    <span v-if="h.kind === 'sabak'" class="text-success-emphasis fw-semibold">This is new memorization, so it moves each of them forward.</span>
                                </p>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <button type="button" class="btn btn-sm btn-success" :disabled="copyingHifz || !hifzCopyTo.length" @click="copyHifz(h)">
                                        <span v-if="copyingHifz" class="spinner-border spinner-border-sm"></span>
                                        <span v-else>{{ hifzCopyTo.length ? `Copy to ${hifzCopyTo.length} student${hifzCopyTo.length === 1 ? '' : 's'}` : 'Copy' }}</span>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-link text-muted" :disabled="copyingHifz"
                                            @click="hifzCopyTo = hifzClassmates.map((s) => s.membership_id)">Choose all</button>
                                    <button type="button" class="btn btn-sm btn-link text-muted" :disabled="copyingHifz"
                                            @click="openHifzCopy = null">Cancel</button>
                                </div>
                                <p v-if="hifzCopyError" class="text-danger small mt-2 mb-0" role="alert">{{ hifzCopyError }}</p>
                            </div>
                            <p v-if="hifzCopyDone && hifzCopyDone.id === h.id" class="text-success small mb-1" role="status">{{ hifzCopyDone.text }}</p>
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
                        <GroupMediaPicker v-model="storyPhotos" :disabled="posting" class="mb-2" v-bind="storyMedia" />
                        <p v-if="storyPhotos.length" class="text-muted small mb-2">
                            Photos are shown only to families who have given photo consent.
                        </p>
                        <!-- "Send later", on the SCHOOL's clock (the zone the server named). -->
                        <SendLaterField v-if="storyScheduling" class="mb-2"
                                        v-model:enabled="storyLater.enabled.value" v-model="storyLater.value.value"
                                        :timezone="storyScheduling.timezone" :max-days="storyScheduling.max_days_ahead"
                                        :error="storyLater.error.value" :disabled="posting" />
                        <div class="d-flex align-items-center gap-2">
                            <span v-if="postError" class="text-danger small">{{ postError }}</span>
                            <button class="btn btn-sm btn-success ms-auto" :disabled="posting || !composeBody || !storyLater.ready.value" @click="submitPost">
                                <span v-if="posting" class="spinner-border spinner-border-sm me-1"></span>{{ storyLater.enabled.value ? 'Schedule' : 'Post' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Written and waiting, or refused at release: Edit, Send now, Cancel. -->
                <ScheduledItems :rows="scheduledStories" :timezone="storyScheduling?.timezone" :max-days="storyScheduling?.max_days_ahead"
                                :busy="scheduledBusy" :error="scheduledError"
                                @send-now="sendStoryNow" @cancel="cancelStory" @save="saveStory" />

                <div v-if="postsLoading" class="text-center py-3"><span class="spinner-border text-success"></span></div>
                <div v-else-if="!posts.length" class="text-muted small">Nothing posted yet.</div>
                <div v-else class="d-flex flex-column gap-3">
                    <article v-for="post in posts" :key="post.id" class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <h2 v-if="post.title && editingStoryId !== post.id" class="h6 mb-1">{{ post.title }}</h2>
                                <span class="ms-auto d-flex gap-3">
                                    <!-- After it is sent: title and text only, from the server's own `can_edit`. -->
                                    <button v-if="canEditStory(post) && editingStoryId !== post.id" class="btn btn-sm btn-link p-0"
                                            aria-label="Edit this story" data-test="story-edit" :disabled="storyEditBusy" @click="startStoryEdit(post)">Edit</button>
                                    <button class="btn btn-sm btn-link text-danger p-0"
                                            :disabled="removingPost === post.id" @click="deletePost(post)">Remove</button>
                                </span>
                            </div>
                            <p class="text-muted small mb-2">
                                {{ post.author?.name || 'You' }} · {{ when(post.published_at ?? post.created_at) }}
                                <span v-if="editedMarker(post.edited_at, when)" class="fst-italic" data-test="story-edited"
                                      :title="editedMarker(post.edited_at, when)?.title">· Edited</span>
                            </p>
                            <StoryEditForm v-if="editingStoryId === post.id" :post="post" :busy="storyEditBusy" :error="storyEditError"
                                           class="mb-2" @save="(draft) => submitStoryEdit(post, draft)" @cancel="cancelStoryEdit" />
                            <p v-else class="mb-2" style="white-space: pre-wrap;">{{ post.body }}</p>
                            <div v-if="post.attachments?.length" class="d-flex flex-wrap gap-2">
                                <TeacherPhoto v-for="a in post.attachments" :key="a.id"
                                              :src="a.download_path" :name="a.file_name"
                                              :mime="a.mime_type" :is-video="a.is_video"
                                              :playback-path="a.playback_ticket_path" />
                            </div>
                            <!-- 🤲 👍 💯 ❓ — every name, families included (you can already read the class). -->
                            <MessageSignals v-if="post.reactions" v-model:reactions="post.reactions"
                                            :send="(key: string, on: boolean) => reactToPost(post, key, on)" />
                            <!-- "Seen by 4 of 7 parents" — only while the school has receipts on. -->
                            <StorySeenLine :enabled="storyReads.enabled" :seen-by="post.seen_by"
                                           :seen-count="post.seen_count" :audience-count="post.audience_count"
                                           :tracked="post.seen_tracked" :since="post.seen_since"
                                           :unreachable="storyReads.unreachable_count" />
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
                            <GroupMediaPicker v-if="!messageLater.enabled.value" v-model="composePhotos" :disabled="sendingCompose" class="mt-2" v-bind="messageMedia" />
                            <!-- "Send later", on the SCHOOL's clock. Text only: a photo cannot wait. -->
                            <SendLaterField v-if="messageScheduling" class="mt-2"
                                            v-model:enabled="messageLater.enabled.value" v-model="messageLater.value.value"
                                            :timezone="messageScheduling.timezone" :max-days="messageScheduling.max_days_ahead"
                                            :error="messageLater.error.value" :disabled="sendingCompose" />
                            <p v-if="messageLater.enabled.value" class="text-muted small mt-1 mb-0">
                                A message scheduled for later is text only. You can cancel it, or send it now, from the
                                Scheduled list until it goes out.
                            </p>

                            <p class="text-muted small mt-2 mb-2">
                                <template v-if="composeForm.about_membership_id">
                                    Only that child's guardians will see this.
                                </template>
                                <template v-else>
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    Every family in this class will see this conversation.
                                    <template v-if="composePhotos.length">
                                        Photos are shown only to families who have given photo consent.
                                    </template>
                                </template>
                            </p>

                            <div class="d-flex align-items-center gap-2">
                                <button class="btn btn-sm btn-success"
                                        :disabled="sendingCompose || !composeForm.subject.trim() || !messageLater.ready.value
                                            || (messageLater.enabled.value ? !composeForm.body.trim() : (!composeForm.body.trim() && !composePhotos.length))"
                                        @click="createThread">
                                    {{ sendingCompose ? 'Sending…' : (messageLater.enabled.value ? 'Schedule' : 'Send') }}
                                </button>
                                <button class="btn btn-sm btn-link text-muted" @click="composing = false">Cancel</button>
                                <span v-if="composeError" class="text-danger small">{{ composeError }}</span>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Written and waiting, or refused at send time: Edit, Send now, Cancel. -->
                <ScheduledItems v-if="!openedThread" :rows="scheduledMessages"
                                :timezone="messageScheduling?.timezone" :max-days="messageScheduling?.max_days_ahead"
                                :busy="scheduledBusy" :error="scheduledError"
                                @send-now="sendMessageNow" @cancel="cancelMessage" @save="saveMessage" />

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
                                        <span v-if="threadNewLabel(thread)" class="badge bg-success ms-1">{{ threadNewLabel(thread) }}</span>
                                    </div>
                                    <div class="text-muted small">
                                        <span v-if="thread.about">About {{ thread.about.name || name(thread.about.contact ?? thread.about) }} · </span>
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
                             :class="m.is_mine ? 'align-self-end text-end' : ''" class="tc-bubble" style="max-width: 85%;">
                            <div class="text-muted small">
                                {{ m.is_mine ? 'You' : (m.author?.name || 'Guardian') }} · {{ when(m.created_at) }}<template v-if="m.edited_at"> · <span :title="when(m.edited_at)">Edited</span></template>
                            </div>
                            <!-- The author may change the words of their own message while the conversation is open. -->
                            <EditableMessageBody :body="m.body" :can-edit="!!m.can_edit" :has-media="messageHasMedia(m)"
                                                 :align-end="m.is_mine" :save="(body: string) => editMessage(m, body)">
                                <div v-if="m.body" class="rounded px-3 py-2 d-inline-block text-start"
                                     :class="m.is_mine ? 'bg-success-subtle' : 'bg-light'"
                                     style="white-space: pre-wrap;">{{ m.body }}</div>
                            </EditableMessageBody>
                            <div v-if="m.attachments?.length" class="d-flex flex-wrap gap-2 mt-1"
                                 :class="m.is_mine ? 'justify-content-end' : ''">
                                <TeacherPhoto v-for="a in m.attachments" :key="a.id"
                                              :src="a.download_path" :name="a.file_name"
                                              :mime="a.mime_type" :is-video="a.is_video"
                                              :playback-path="a.playback_ticket_path" />
                            </div>
                            <div v-else-if="m.media_withheld" class="text-muted small fst-italic mt-1">
                                A photo in this message is hidden.
                            </div>
                            <!-- 🤲 👍 💯 ❓ and, under your own messages, which family has read it. -->
                            <MessageSignals v-model:reactions="m.reactions" :read-by="m.read_by"
                                            :show-receipt="m.is_mine" :align-end="m.is_mine"
                                            :can-react="!openedThread.is_closed"
                                            :send="(key: string, on: boolean) => reactTo(m, key, on)" />
                        </div>
                    </div>
                    <div class="card-footer bg-white">
                        <div v-if="openedThread.is_closed" class="text-muted small">This conversation is closed.</div>
                        <template v-else>
                            <div v-if="replyError" class="alert alert-danger small py-2">{{ replyError }}</div>
                            <div class="d-flex gap-2 align-items-end">
                                <textarea v-model="replyBody" class="form-control" rows="2"
                                          placeholder="Write a reply…" @keydown.ctrl.enter="sendReply"></textarea>
                                <button class="btn btn-success" :disabled="(!replyBody.trim() && !replyPhotos.length) || sendingReply" @click="sendReply">
                                    <span v-if="sendingReply" class="spinner-border spinner-border-sm"></span>
                                    <span v-else>Send</span>
                                </button>
                            </div>
                            <GroupMediaPicker v-model="replyPhotos" :disabled="sendingReply" class="mt-2" v-bind="messageMedia" />
                            <p v-if="replyPhotos.length && openedThread.scope === 'group'" class="text-muted small mb-0 mt-1">
                                This conversation is with the whole class. Photos are shown only to families who have given photo consent.
                            </p>
                        </template>
                    </div>
                </div>
            </section>

            <!-- ================================================ LESSON PLANS -->
            <section v-else-if="activeTab === 'lessons'">
                <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                    <button class="btn btn-sm btn-outline-secondary" @click="shiftWeek(-1)">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <span class="small fw-semibold">{{ weekLabel }}</span>
                    <button class="btn btn-sm btn-outline-secondary" @click="shiftWeek(1)">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <div class="flex-grow-1"></div>
                    <div class="btn-group btn-group-sm">
                        <button class="btn" :class="planView === 'day' ? 'btn-success' : 'btn-outline-secondary'"
                                @click="planView = 'day'">Day</button>
                        <button class="btn" :class="planView === 'week' ? 'btn-success' : 'btn-outline-secondary'"
                                @click="planView = 'week'">Week</button>
                    </div>
                </div>

                <div v-if="planView === 'day'" class="d-flex gap-1 mb-3 flex-wrap">
                    <!-- A day with several subjects' plans says how many; a
                         dot alone would read as "this day is planned" when it
                         may hold one subject of four. -->
                    <button v-for="d in weekDays" :key="d.iso" type="button"
                            class="btn btn-sm"
                            :class="d.iso === planDate ? 'btn-success' : (dayPlansOn(d.iso).length ? 'btn-outline-success' : 'btn-outline-secondary')"
                            :aria-label="`${d.label}: ${dayPlansOn(d.iso).length} plan${dayPlansOn(d.iso).length === 1 ? '' : 's'}`"
                            @click="planDate = d.iso">
                        {{ d.label }}<i v-if="dayPlansOn(d.iso).length === 1" class="bi bi-dot"></i>
                        <span v-else-if="dayPlansOn(d.iso).length > 1" class="ms-1 small">·{{ dayPlansOn(d.iso).length }}</span>
                    </button>
                </div>

                <!-- The day's plans, one per subject. Shown once a day has one:
                     before that the form below IS the new plan, and a lone
                     "add" button would only duplicate it. -->
                <div v-if="planView === 'day' && dayPlans.length" class="d-flex gap-1 mb-3 flex-wrap align-items-center"
                     role="group" aria-label="This day's plans">
                    <button v-for="p in dayPlans" :key="p.id" type="button" class="btn btn-sm"
                            :class="p.id === planId ? 'btn-success' : 'btn-outline-secondary'"
                            :aria-pressed="p.id === planId" @click="p.id !== planId && selectPlan(p.id)">
                        {{ planLabel(p) }}
                    </button>
                    <span v-if="planId === null" class="btn btn-sm btn-success disabled" aria-current="true">
                        New plan
                    </span>
                    <button v-else type="button" class="btn btn-sm btn-link px-1" @click="addSubjectPlan">
                        <i class="bi bi-plus-lg me-1"></i>Add a plan for another subject
                    </button>
                </div>

                <!-- ------------------------------------------------ DAY VIEW -->
                <template v-if="planView === 'day'">
                    <!-- The card holds exactly what is touched daily. Everything
                         else is one tap away, never zero taps in the way. -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <!-- Derived, never stored: storing the teacher's name
                                 or the roster count would let a row disagree with
                                 the account and the roster it came from. -->
                            <div class="text-muted small mb-2">
                                {{ planDayLabel }} · {{ group?.name }} · {{ students.length }} students
                            </div>

                            <!-- Pickers when the school's pacing guide has been
                                 imported, plain text when it has not — so a
                                 tenant with no guide still gets a working form. -->
                            <div class="row g-2 mb-2">
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Grade</label>
                                    <select v-if="curriculum.grades.length" class="form-select form-select-sm"
                                            style="min-width:10rem" v-model="planForm.grade_label"
                                            @change="onGradeChange">
                                        <option value="">—</option>
                                        <option v-for="g in curriculum.grades" :key="g" :value="g">{{ g }}</option>
                                    </select>
                                    <input v-else v-model="planForm.grade_label" type="text" maxlength="32"
                                           class="form-control form-control-sm" style="width:7rem" placeholder="e.g. Pre-K">
                                </div>
                                <div class="col-12 col-sm">
                                    <label class="form-label small text-muted mb-1">Subject</label>
                                    <!-- The list is the school's IMPORTED pacing guide, and a
                                         subject the guide has no column for was unreachable:
                                         Al-Razi's guide carries "Qur'an & Islamic Studies" as
                                         one subject and no Arabic at all, so an Arabic lesson
                                         plan could not be written at all — not merely
                                         inconvenienced. "Other" is the escape, and the week
                                         picker below falls back to a plain number when the
                                         chosen subject has no imported weeks. -->
                                    <select v-if="curriculum.subjects.length && !subjectOther"
                                            class="form-select form-select-sm"
                                            v-model="planForm.subject" @change="onSubjectPick">
                                        <option value="">—</option>
                                        <!-- A subject another plan already holds that
                                             day is shown but not offered: one plan per
                                             subject per day. -->
                                        <option v-for="s in curriculum.subjects" :key="s" :value="s"
                                                :disabled="takenSubjects.has(subjectKey(s))">
                                            {{ s }}{{ isCombinedGuideColumn(s) ? ' (school pacing-guide column)' : '' }}{{ takenSubjects.has(subjectKey(s)) ? ' — already planned' : '' }}
                                        </option>
                                        <option :value="SUBJECT_OTHER">Other…</option>
                                    </select>
                                    <div v-else class="d-flex gap-1">
                                        <input v-model="planForm.subject" type="text" maxlength="64"
                                               class="form-control form-control-sm"
                                               placeholder="e.g. Arabic" @change="onSubjectChange">
                                        <button v-if="curriculum.subjects.length" type="button"
                                                class="btn btn-sm btn-link px-1 text-muted text-nowrap"
                                                title="Back to the school's guide"
                                                @click="useGuideSubjects">Guide</button>
                                    </div>
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Week</label>
                                    <select v-if="curriculum.weeks.length && !weekOther" class="form-select form-select-sm"
                                            style="max-width:16rem" v-model.number="planForm.curriculum_week_no"
                                            @change="onWeekPick">
                                        <option :value="null">—</option>
                                        <option v-for="w in curriculum.weeks" :key="w.week_no" :value="w.week_no">
                                            {{ w.week_no }} · {{ w.focus }}{{ w.objective ? ` · ${w.objective}` : '' }}
                                        </option>
                                        <!-- The separated Qur'an, Arabic and Islamic Studies
                                             weeks stop at 8; a later week is still tagged by number. -->
                                        <option :value="WEEK_OTHER">Another week…</option>
                                    </select>
                                    <div v-else class="d-flex gap-1">
                                        <input v-model.number="planForm.curriculum_week_no" type="number" min="1" max="52"
                                               class="form-control form-control-sm" style="width:5.5rem" placeholder="#">
                                        <button v-if="curriculum.weeks.length" type="button"
                                                class="btn btn-sm btn-link px-1 text-muted text-nowrap"
                                                title="Back to the guide's weeks"
                                                @click="useGuideWeeks">Guide</button>
                                    </div>
                                </div>
                            </div>

                            <!-- Said before Save, beside the field, with the way out:
                                 the plan that already has this subject is one tap
                                 away. The server refuses the clash regardless. -->
                            <div v-if="planClash" class="alert alert-warning py-1 px-2 small mb-2" role="alert">
                                <template v-if="planClash.subject">
                                    This day already has a {{ planClash.subject }} plan.
                                </template>
                                <template v-else>
                                    This day already has a plan with no subject. Choose a subject for this one.
                                </template>
                                <button type="button" class="btn btn-sm btn-link p-0 align-baseline"
                                        @click="selectPlan(planClash.id)">Open it</button>
                            </div>

                            <div v-if="curriculum.grades.length" class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                                <button class="btn btn-sm btn-outline-success" :disabled="!canPrefill || prefilling"
                                        @click="prefillFromGuide()">
                                    <i class="bi bi-stars me-1"></i>
                                    {{ prefilling ? 'Filling…' : 'Prefill from pacing guide' }}
                                </button>
                                <span v-if="planForm.prefill_source" class="badge bg-success-subtle text-success-emphasis fw-normal">
                                    from the pacing guide
                                </span>
                                <span v-if="prefillCombined" class="text-muted small" dir="auto">
                                    The school has not separated this week yet: filled from its combined
                                    “{{ prefillCombined }}” guide line.
                                </span>
                                <span v-if="prefillKept" class="text-muted small">
                                    Kept what this plan already says. Clear a field to fill it from the guide.
                                </span>
                                <button v-if="selectedPlan && weekdaysOnly.length > 1" class="btn btn-sm btn-link px-0"
                                        :disabled="copying" @click="copyAcrossWeek">
                                    {{ copying ? 'Copying…' : (selectedPlan.subject ? `Copy this ${selectedPlan.subject} plan to the rest of this week` : 'Copy to the rest of this week') }}
                                </button>
                            </div>

                            <!-- The guide gives ONE code per subject per week and a
                                 week is four or five lessons, so a prefilled code is
                                 a draft the teacher owns, not a fact. -->
                            <p v-if="planForm.prefill_source && !planHidden.has('standard_code')" class="text-muted small mb-2">
                                Prefilled fields are editable. Verify standard codes against the
                                official DPI documents before citing them outside the school.
                            </p>

                            <label class="form-label small text-muted mb-1">
                                Activities <span class="text-danger">*</span>
                            </label>
                            <textarea v-model="planForm.body" rows="4" class="form-control form-control-sm"
                                      placeholder="What will this class actually do?"></textarea>

                            <!-- FILES UNDER ACTIVITIES (T-004.1). A plan owns no bytes:
                                 each file here is one of this class's Files (private,
                                 staff-only unless shared from the Files tab), and the
                                 plan saves the list of ids with the rest of the form.
                                 Uploading goes through the Files upload, staff-only. -->
                            <div class="mt-2" data-test="plan-files">
                                <div class="d-flex align-items-baseline gap-2">
                                    <label class="form-label small text-muted mb-1">Files</label>
                                    <span class="text-muted small">{{ planForm.attachments.length }} / {{ MAX_PLAN_FILES }}</span>
                                </div>
                                <ul v-if="planForm.attachments.length" class="list-unstyled mb-2">
                                    <li v-for="a in planForm.attachments" :key="a.id"
                                        class="d-flex align-items-center gap-2 small py-1 border-bottom">
                                        <i class="bi bi-paperclip text-muted"></i>
                                        <span class="flex-grow-1 text-truncate">
                                            {{ a.title || a.original_name }}
                                            <span class="text-muted">· {{ Math.max(1, Math.round((a.size_bytes || 0) / 1024)) }} KB</span>
                                        </span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                title="Download" @click="downloadResource(a)">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-link text-danger"
                                                @click="detachPlanFile(a.id)">Remove from plan</button>
                                    </li>
                                </ul>
                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <select v-if="unattachedResources.length" v-model="planFilePick"
                                            class="form-select form-select-sm" style="max-width:16rem"
                                            :disabled="planFilesFull" @change="attachPickedFile">
                                        <option value="">Add from this class’s files…</option>
                                        <option v-for="r in unattachedResources" :key="r.id" :value="r.id">{{ r.title }}</option>
                                    </select>
                                    <label class="btn btn-sm btn-outline-success mb-0"
                                           :class="{ disabled: planFilesFull || planFileBusy }">
                                        <i class="bi bi-upload me-1"></i>{{ planFileBusy ? 'Uploading…' : 'Upload a file' }}
                                        <input type="file" class="d-none" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                               :disabled="planFilesFull || planFileBusy" @change="uploadPlanFile">
                                    </label>
                                </div>
                                <p class="text-muted small mb-0 mt-1">
                                    Attaching a file here does not share it with families. A file already shared from Files stays shared.
                                </p>
                                <p v-if="planFileError" class="text-danger small mb-0">{{ planFileError }}</p>
                            </div>

                            <label class="form-label small text-muted mb-1 mt-2">Formative check</label>
                            <input v-model="planForm.assessment_formative" type="text"
                                   class="form-control form-control-sm"
                                   placeholder="How will you know they got it?">
                        </div>
                    </div>

                    <!-- Accordion, not a stepper: a plan is written on Sunday and
                         its reflection on Tuesday afternoon. A section opens
                         itself when it already has content, or nobody would know
                         a written plan was not empty. -->
                    <div v-for="sec in visiblePlanSections" :key="sec.key" class="card border-0 shadow-sm mb-2">
                        <button type="button"
                                class="card-body d-flex align-items-center gap-2 w-100 text-start border-0 bg-transparent"
                                @click="togglePlanSection(sec.key)">
                            <i :class="`bi ${planOpen[sec.key] ? 'bi-chevron-down' : 'bi-chevron-right'} text-muted`"></i>
                            <span class="fw-semibold small">{{ sec.label }}</span>
                            <i class="bi bi-circle-fill ms-1"
                               :class="sectionFilled(sec) ? 'text-success' : 'text-body-tertiary'"
                               style="font-size:.5rem"></i>
                            <span class="text-muted small d-none d-sm-inline ms-auto">{{ sec.hint }}</span>
                        </button>

                        <div v-if="planOpen[sec.key]" class="card-body pt-0">
                            <template v-for="f in sec.fields" :key="f.key">
                                <label class="form-label small text-muted mb-1">{{ f.label }}</label>

                                <!-- Learning outcomes: a real list, not a textarea
                                     pretending. Stored as json. -->
                                <template v-if="f.key === 'learning_outcomes'">
                                    <div v-for="(o, i) in planForm.learning_outcomes" :key="i"
                                         class="d-flex gap-1 mb-1">
                                        <input v-model="planForm.learning_outcomes[i]" type="text" maxlength="500"
                                               class="form-control form-control-sm" :placeholder="`Outcome ${i + 1}`">
                                        <button class="btn btn-sm btn-link text-danger px-1"
                                                @click="planForm.learning_outcomes.splice(i, 1)">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <button class="btn btn-sm btn-link px-0 mb-2"
                                            :disabled="planForm.learning_outcomes.length >= 10"
                                            @click="planForm.learning_outcomes.push('')">+ Add an outcome</button>
                                </template>

                                <!-- Teaching methods: the template's checkbox set. -->
                                <template v-else-if="f.key === 'teaching_methods'">
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <button v-for="m in TEACHING_METHODS" :key="m.value" type="button"
                                                class="btn btn-sm"
                                                :class="planForm.teaching_methods.includes(m.value) ? 'btn-success' : 'btn-outline-secondary'"
                                                @click="toggleMethod(m.value)">
                                            {{ m.label }}
                                        </button>
                                    </div>
                                    <input v-if="planForm.teaching_methods.includes('other')"
                                           v-model="planForm.teaching_methods_other" type="text" maxlength="255"
                                           class="form-control form-control-sm mb-2" placeholder="Other — which?">
                                </template>

                                <!-- Standard code: typing searches the school's pacing
                                     guide by code or topic, and a pick fills the code,
                                     the objective and the week. The Description box
                                     below is NOT filled: the guide carries codes and a
                                     weekly focus, not the state's wording (see
                                     CurriculumWeek::toPrefillArray). Only with a guide —
                                     a school without one keeps the plain box. -->
                                <div v-else-if="f.key === 'standard_code' && curriculum.grades.length"
                                     class="position-relative mb-2">
                                    <input :id="`std-${f.key}`"
                                           v-model="planForm[f.key]" type="text" maxlength="32" autocomplete="off"
                                           class="form-control form-control-sm"
                                           placeholder="Type a code or topic, e.g. NF.1 or fractions"
                                           role="combobox" aria-autocomplete="list"
                                           :aria-expanded="stdOpen === f.key && stdMatches.length > 0"
                                           :aria-controls="`std-${f.key}-list`"
                                           :aria-activedescendant="stdOpen === f.key && stdActive >= 0 ? `std-${f.key}-opt-${stdActive}` : undefined"
                                           @input="onStandardInput(f.key, $event)"
                                           @focus="onStandardInput(f.key, $event, false)"
                                           @compositionstart="stdComposing = true"
                                           @compositionend="stdComposing = false"
                                           @keydown="onStandardKey" @blur="closeStandards">

                                    <ul v-if="stdOpen === f.key && stdMatches.length" :id="`std-${f.key}-list`"
                                        role="listbox" class="list-group position-absolute w-100 shadow tc-std-list">
                                        <template v-for="(m, i) in stdMatches"
                                                  :key="`${m.grade_label}|${m.subject}|${m.standard_code}|${m.focus}|${m.objective ?? ''}`">
                                            <!-- Where the form's own grade and subject end: a
                                                 heading, not an option, so it cannot be picked. -->
                                            <li v-if="!m.in_scope && (i === 0 || stdMatches[i - 1].in_scope)"
                                                role="presentation"
                                                class="list-group-item py-1 px-2 text-uppercase text-muted fw-semibold tc-std-divider"
                                                @mousedown.prevent>
                                                Other grades and subjects
                                            </li>
                                            <!-- mousedown, not click: a click lands after the
                                                 field's blur has already closed the list. -->
                                            <li :id="`std-${f.key}-opt-${i}`"
                                                role="option" :aria-selected="i === stdActive"
                                                class="list-group-item list-group-item-action py-1 px-2 small"
                                                :class="{ 'bg-success-subtle': i === stdActive }"
                                                @mousedown.prevent="pickStandard(m)" @mouseenter="stdActive = i">
                                            <div class="d-flex gap-2 align-items-baseline">
                                                <span class="fw-semibold text-nowrap">{{ m.standard_code || 'No code' }}</span>
                                                <span>{{ m.focus }}</span>
                                            </div>
                                            <div v-if="m.objective" class="text-muted small" dir="auto">{{ m.objective }}</div>
                                            <div class="text-muted tc-std-meta">
                                                {{ m.grade_label }} · {{ m.subject }} · {{ weeksLabel(m.weeks) }}
                                            </div>
                                            </li>
                                        </template>
                                    </ul>
                                    <div v-else-if="stdOpen === f.key && stdEmptyFor !== null && stdEmptyFor === stdTyped"
                                         class="form-text">
                                        Nothing in the pacing guide matches. What you type is kept as written.
                                    </div>
                                </div>

                                <textarea v-else v-model="planForm[f.key]" :rows="f.rows || 2"
                                          class="form-control form-control-sm mb-2"
                                          :placeholder="f.placeholder || ''"></textarea>
                            </template>

                            <!-- The template asks for "students needing follow-up".
                                 It is deliberately not a field here: a free-text box
                                 naming children would be a record ABOUT a child on a
                                 row GroupAudience cannot govern. Naming a child is
                                 already a governed act, one tab over. -->
                            <div v-if="sec.key === 'reflection'" class="alert alert-light border small mb-0">
                                Someone needs following up?
                                <button class="btn btn-sm btn-link p-0 align-baseline"
                                        @click="activeTab = 'messages'">Message their family</button>
                                rather than naming them here.
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 mt-3">
                        <button class="btn btn-sm btn-success" :disabled="!canSavePlan(planSaving, planForm.body, planClash)"
                                @click="savePlan">
                            {{ planSaving ? 'Saving…' : 'Save plan' }}
                        </button>
                        <button v-if="selectedPlan" class="btn btn-sm btn-link text-danger"
                                :disabled="planDeleting" @click="deletePlan">Remove {{ dayPlans.length > 1 ? `the ${planLabel(selectedPlan)} plan` : '' }}</button>
                        <span v-if="planSaved" class="text-success small">
                            <i class="bi bi-check-circle me-1"></i>Saved
                        </span>
                        <span v-if="planError" class="text-danger small">{{ planError }}</span>
                    </div>
                </template>

                <!-- ----------------------------------------------- WEEK VIEW -->
                <!-- Template section 3, rendered from the five daily plans the tab
                     already fetched. NOT a second store: a weekly table would be a
                     copy of these same fields and would drift the first time a
                     teacher edited Tuesday and not the grid. -->
                <template v-else>
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th style="width:6rem">Day</th>
                                    <th>Subject</th>
                                    <th v-if="!planHidden.has('standard_code')">Standard</th>
                                    <th>Objective</th>
                                    <th>Activities</th>
                                    <th v-if="!planHidden.has('differentiation_support')">Differentiation</th>
                                    <th>Assessment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- One row per plan, so a day with three
                                     subjects is three rows; a day with none is
                                     one row of dashes, still a way into it. -->
                                <template v-for="d in weekdaysOnly" :key="d.iso">
                                    <tr v-for="(p, i) in (dayPlansOn(d.iso).length ? dayPlansOn(d.iso) : [null])"
                                        :key="`${d.iso}-${p?.id ?? 'none'}`"
                                        style="cursor:pointer" @click="jumpToDay(d.iso, p?.id ?? null)">
                                        <td class="fw-semibold small">{{ i === 0 ? d.label : '' }}</td>
                                        <td class="small">{{ p ? planLabel(p) : '—' }}</td>
                                        <td v-if="!planHidden.has('standard_code')" class="small">{{ p?.standard_code || '—' }}</td>
                                        <td class="small">{{ p?.objective || '—' }}</td>
                                        <td class="small">
                                            {{ p?.body || '—' }}
                                            <span v-if="p?.attachments?.length" class="text-muted text-nowrap ms-1"
                                                  :title="`${p.attachments.length} file(s) attached`">
                                                <i class="bi bi-paperclip"></i>{{ p.attachments.length }}
                                            </span>
                                        </td>
                                        <td v-if="!planHidden.has('differentiation_support')" class="small">{{ p?.differentiation_support || '—' }}</td>
                                        <td class="small">{{ p?.assessment_formative || '—' }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Below md the grid stacks: a six-column table on a phone is
                         unreadable however it scrolls. -->
                    <div class="d-md-none d-flex flex-column gap-2">
                        <button v-for="d in weekdaysOnly" :key="d.iso" type="button"
                                class="card border-0 shadow-sm text-start" @click="jumpToDay(d.iso)">
                            <div class="card-body py-2">
                                <div class="fw-semibold small">{{ d.label }}</div>
                                <div v-if="!dayPlansOn(d.iso).length" class="text-muted small">No plan yet</div>
                                <div v-for="p in dayPlansOn(d.iso)" :key="p.id" class="text-muted small">
                                    <span v-if="dayPlansOn(d.iso).length > 1 || p.subject" class="fw-semibold">{{ planLabel(p) }}:</span>
                                    {{ p.body }}
                                    <span v-if="p.attachments?.length" class="text-nowrap ms-1"><i class="bi bi-paperclip"></i>{{ p.attachments.length }}</span>
                                </div>
                            </div>
                        </button>
                    </div>
                </template>
            </section>

            <!-- ======================================================= GRADES -->
            <section v-else-if="activeTab === 'grades'">
                <template v-if="!openAssignment">
                    <!-- Work | Students. Students is the teacher's own view of each
                         child's average, which this app never had: it reads the same
                         endpoint (and the same arithmetic) a parent's screen does. -->
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Grades view">
                            <button type="button" class="btn" :class="gradesView === 'work' ? 'btn-success' : 'btn-outline-success'"
                                    @click="gradesView = 'work'">Work</button>
                            <button type="button" class="btn" :class="gradesView === 'students' ? 'btn-success' : 'btn-outline-success'"
                                    @click="showStudentsView">Students</button>
                        </div>
                        <button v-if="gradesView === 'work'" type="button" class="btn btn-sm btn-outline-secondary ms-auto"
                                :aria-expanded="showWeights" @click="toggleWeights">
                            <i class="bi bi-sliders me-1"></i>{{ weightingEnabled || !canChangeWeights ? 'Weights' : 'Set weights' }}
                        </button>
                    </div>

                    <!-- ============================================ THE CLASS'S WEIGHTS -->
                    <div v-if="gradesView === 'work' && showWeights" class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="fw-semibold mb-1">How much each type of work counts</div>
                            <p class="text-muted small mb-2">
                                Each type of work counts by its weight, however many pieces of it there are: with Test at
                                40 and Homework at 10, Tests make up four fifths of the average whether a child has done
                                one Homework or ten. Weights are relative, so they do not have to add up to 100, and a
                                type nobody has been marked on yet changes nothing. Give one piece of work its own weight
                                when you set it and it counts on its own, beside the types. Leave the weights unset for
                                a plain average.
                            </p>
                            <div class="row g-2 align-items-end">
                                <div v-for="t in workTypes" :key="t.key" class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1" :for="`weight-${t.key}`">{{ t.label }}</label>
                                    <input :id="`weight-${t.key}`" v-model="weightsForm[t.key]" type="number" inputmode="numeric"
                                           min="0" :max="weightMax" step="1" class="form-control form-control-sm" style="width:5.5rem"
                                           :disabled="!canChangeWeights">
                                </div>
                                <div v-if="canChangeWeights" class="col-auto d-flex gap-2">
                                    <button class="btn btn-sm btn-success" :disabled="savingWeights" @click="saveWeights">
                                        {{ savingWeights ? 'Saving…' : 'Save weights' }}
                                    </button>
                                    <button v-if="weightingEnabled && !confirmClearWeights" class="btn btn-sm btn-outline-danger"
                                            :disabled="savingWeights" @click="confirmClearWeights = true">Clear</button>
                                </div>
                            </div>
                            <!-- A teacher limited to some subjects reads the weights and cannot change them:
                                 they move every subject's average, which families read (review F5). -->
                            <p v-if="!canChangeWeights" class="text-muted small mt-2 mb-0" data-test="weights-read-only">
                                <i class="bi bi-lock me-1"></i>These weights count in every subject's average, so only a
                                teacher of all the subjects in this class, or the office, can change them.
                            </p>
                            <div v-if="canChangeWeights && confirmClearWeights" class="alert alert-warning small mt-3 mb-0">
                                Clear the weights? Every average goes back to the plain one, and any weight given to
                                one piece of work is removed too, including work another teacher of this class set.
                                <div class="mt-2 d-flex gap-2">
                                    <button class="btn btn-sm btn-danger" :disabled="savingWeights" @click="clearWeights">Clear them</button>
                                    <button class="btn btn-sm btn-light" @click="confirmClearWeights = false">Keep them</button>
                                </div>
                            </div>
                            <p v-if="weightsSaved" class="text-success small mt-2 mb-0"><i class="bi bi-check-circle me-1"></i>Saved</p>
                            <p v-if="gradesError" class="text-danger small mt-2 mb-0">{{ gradesError }}</p>
                        </div>
                    </div>

                    <template v-if="gradesView === 'work'">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div v-if="editingId !== null" class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-warning-subtle text-warning-emphasis">Editing</span>
                                <span class="small text-muted">Changes apply to work already marked.</span>
                            </div>
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-sm">
                                    <label class="form-label small text-muted mb-1">{{ editingId !== null ? 'Work' : 'New work' }}</label>
                                    <input v-model="assignmentForm.title" type="text" maxlength="200"
                                           class="form-control form-control-sm" placeholder="e.g. Spelling test">
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Marked on</label>
                                    <select v-model="assignmentForm.scale" class="form-select form-select-sm" style="min-width:9.5rem">
                                        <option v-for="sc in gradingScales" :key="sc" :value="sc">{{ SCALE_LABELS[sc] ?? sc }}</option>
                                    </select>
                                </div>
                                <!-- Only points work has a maximum to ask for. On the
                                     levels scale the maximum is implied by the scale,
                                     and a box here would offer a five-level assignment
                                     nothing else in the system can read. -->
                                <div v-if="assignmentForm.scale === 'points'" class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Out of</label>
                                    <input v-model.number="assignmentForm.points_possible" type="number" min="1"
                                           class="form-control form-control-sm" style="width:6rem">
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Set on</label>
                                    <input v-model="assignmentForm.assigned_on" type="date"
                                           class="form-control form-control-sm" style="width:10rem">
                                </div>
                            </div>

                            <!-- Row 2: what the work is FOR. Subject first, then the
                                 standard it teaches, so the search can rank that
                                 subject's standards first. -->
                            <div class="row g-2 align-items-end mt-1">
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1" for="work-subject">
                                        Subject<span v-if="gradeSubjects.length" class="text-danger"> *</span>
                                    </label>
                                    <select v-if="gradeSubjects.length" id="work-subject" v-model="assignmentForm.subject"
                                            class="form-select form-select-sm" style="min-width:11rem">
                                        <option value="" disabled>Choose…</option>
                                        <option v-for="s in gradeSubjects" :key="s.key" :value="s.name">{{ s.name }}</option>
                                        <!-- Work already filed under a subject the school has since
                                             retired keeps its name rather than losing it to a select
                                             with no such option. -->
                                        <option v-if="assignmentForm.subject && !gradeSubjects.some((s) => s.name === assignmentForm.subject)"
                                                :value="assignmentForm.subject">{{ assignmentForm.subject }}</option>
                                    </select>
                                    <input v-else id="work-subject" v-model="assignmentForm.subject" type="text" maxlength="64"
                                           class="form-control form-control-sm" style="min-width:11rem" placeholder="Optional">
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1" for="work-type">Type</label>
                                    <select id="work-type" v-model="assignmentForm.type" class="form-select form-select-sm" style="min-width:8.5rem">
                                        <option value="">No type</option>
                                        <option v-for="t in workTypes" :key="t.key" :value="t.key">{{ t.label }}</option>
                                    </select>
                                </div>
                                <!-- A weight of its own, only where the class has weights: the
                                     server refuses one otherwise. The placeholder says what it
                                     inherits, so leaving it blank is a decision a teacher can read. -->
                                <div v-if="weightingEnabled" class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1" for="work-weight">Weight</label>
                                    <input id="work-weight" v-model="assignmentForm.weight" type="number" inputmode="numeric" min="0"
                                           :max="weightMax" step="1" class="form-control form-control-sm" style="width:6.5rem"
                                           :placeholder="inheritedWeightText">
                                </div>
                                <div v-if="standardsEnabled" class="col-12 col-md">
                                    <label class="form-label small text-muted mb-1" for="work-standard">Standard</label>
                                    <StandardPicker v-model="assignmentForm.standard" :masjid-id="masjidId"
                                                    :grade="singleGrade" :subject="assignmentForm.subject || null"
                                                    :group-id="groupId" input-id="work-standard" />
                                </div>
                                <div class="col-auto ms-auto d-flex gap-2">
                                    <button v-if="editingId !== null" class="btn btn-sm btn-light" :disabled="creatingAssignment"
                                            @click="cancelEdit">Cancel</button>
                                    <button class="btn btn-sm btn-success"
                                            :disabled="creatingAssignment || !workReady"
                                            @click="saveWork">{{ editingId !== null ? 'Save changes' : 'Add' }}</button>
                                </div>
                            </div>
                            <p v-if="gradeSubjects.length && !assignmentForm.subject && assignmentForm.title.trim()"
                               class="text-muted small mt-2 mb-0">Choose the subject this work is for.</p>
                            <p v-if="gradesError && !showWeights" class="text-danger small mt-2 mb-0">{{ gradesError }}</p>
                        </div>
                    </div>

                    <p v-if="!assignments.length" class="text-muted small">No work set yet.</p>
                    <div v-else class="list-group">
                        <!-- A div, not a button: an Edit button inside a button is not valid
                             HTML and steals the tap. The open target is its own button. -->
                        <div v-for="a in assignments" :key="a.id" class="list-group-item d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-link text-start text-decoration-none text-body p-0 flex-grow-1 d-flex align-items-center gap-3"
                                    @click="openScores(a)">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small">{{ a.title }}</div>
                                    <div class="text-muted small">
                                        {{ a.assigned_on }} · {{ a.scale === 'levels' ? 'levels 4–1' : a.scale === 'simple' ? 'Excellent / Good / Needs work' : `out of ${a.points_possible}` }}
                                    </div>
                                    <div class="d-flex flex-wrap gap-1 mt-1">
                                        <span v-if="a.subject" class="badge bg-primary-subtle text-primary-emphasis fw-normal">{{ a.subject }}</span>
                                        <span v-if="a.type_label" class="badge bg-secondary-subtle text-secondary-emphasis fw-normal">{{ a.type_label }}</span>
                                        <span v-if="weightNote(a, classWeights, weightingEnabled)" class="badge bg-light text-muted fw-normal">
                                            {{ weightNote(a, classWeights, weightingEnabled) }}
                                        </span>
                                        <span v-if="a.standard_code || a.curriculum_focus" class="badge bg-success-subtle text-success-emphasis fw-normal"
                                              :title="a.curriculum_focus ?? ''">
                                            {{ a.standard_code || 'Standard' }}
                                        </span>
                                        <span v-if="weightingEnabled && isUntyped(a)" class="badge bg-warning-subtle text-warning-emphasis fw-normal"
                                              title="Work with no type is left out of the weighted average">no type</span>
                                    </div>
                                </div>
                                <span class="badge" :class="a.scored >= a.roster ? 'bg-success-subtle text-success-emphasis' : 'bg-light text-muted'">
                                    {{ a.scored }}/{{ a.roster }} marked
                                </span>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" :aria-label="`Edit ${a.title}`" @click="startEdit(a)">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </div>
                    </div>
                    <p v-if="weightingEnabled && untypedInList > 0" class="text-warning-emphasis small mt-2 mb-0">
                        {{ untypedListNote(untypedInList) }}
                    </p>
                    </template>

                    <!-- ============================================ STUDENTS -->
                    <template v-else>
                        <p v-if="!students.length" class="text-muted small">No students in this class.</p>
                        <div v-else class="list-group">
                            <div v-for="s in students" :key="s.membership_id" class="list-group-item">
                                <button type="button" class="btn btn-link text-start text-decoration-none text-body p-0 w-100 d-flex align-items-center gap-3"
                                        :aria-expanded="openStudentId === s.membership_id" @click="toggleStudent(s)">
                                    <PersonAvatar :avatar="s.contact?.avatar" :first-name="s.contact?.first_name"
                                                  :last-name="s.contact?.last_name" :size="34" />
                                    <span class="fw-semibold small flex-grow-1">{{ name(s.contact) }}</span>
                                    <i class="bi" :class="openStudentId === s.membership_id ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                                </button>

                                <div v-if="openStudentId === s.membership_id" class="mt-3">
                                    <div v-if="studentLoading" class="text-center py-2"><span class="spinner-border spinner-border-sm text-success"></span></div>
                                    <p v-else-if="studentError" class="text-danger small mb-0">{{ studentError }}</p>
                                    <template v-else-if="studentGrades">
                                        <p v-if="!studentGrades.summary.recorded" class="text-muted small mb-0">No marks yet.</p>
                                        <template v-else>
                                            <dl class="row small mb-2">
                                                <template v-for="line in studentLines" :key="line.label">
                                                    <dt class="col-sm-4 fw-semibold">{{ line.label }}</dt>
                                                    <dd class="col-sm-8">{{ line.value }} <span class="text-muted">{{ line.note }}</span></dd>
                                                </template>
                                            </dl>
                                            <p v-if="studentFencedNote" class="text-muted small mb-2">{{ studentFencedNote }}</p>
                                            <p v-if="studentGrades.summary.weighting.untyped_excluded" class="text-warning-emphasis small mb-2">
                                                {{ untypedNoteText(studentGrades.summary.weighting.untyped_excluded) }}, so
                                                {{ studentGrades.summary.weighting.untyped_excluded === 1 ? 'it is' : 'they are' }} left out of the weighted average.
                                            </p>
                                            <div v-if="studentGrades.summary.by_subject.length" class="mb-2">
                                                <div class="text-uppercase text-muted small">By subject</div>
                                                <ul class="list-unstyled small mb-0">
                                                    <li v-for="b in studentGrades.summary.by_subject" :key="b.subject ?? '_none'" class="d-flex justify-content-between gap-3">
                                                        <span>{{ b.subject ?? 'No subject' }}</span>
                                                        <span class="text-muted text-end">{{ subjectLine(b) || '—' }}</span>
                                                    </li>
                                                </ul>
                                            </div>
                                            <div v-if="studentGrades.summary.weighting.by_type.length" class="mb-2">
                                                <div class="text-uppercase text-muted small">By type</div>
                                                <ul class="list-unstyled small mb-0">
                                                    <li v-for="t in studentGrades.summary.weighting.by_type" :key="t.type" class="d-flex justify-content-between gap-3">
                                                        <span>{{ t.label }}<span v-if="t.weight !== null" class="text-muted"> · counts {{ t.weight }}</span></span>
                                                        <span class="text-muted">{{ percentText(t.percent) }} · {{ t.pieces }} piece{{ t.pieces === 1 ? '' : 's' }}</span>
                                                    </li>
                                                </ul>
                                            </div>
                                            <ul class="list-unstyled small mb-0">
                                                <li v-for="(sc, i) in studentGrades.scores" :key="`${sc.assignment?.id ?? 'x'}-${i}`"
                                                    class="border-top py-1 d-flex justify-content-between gap-3">
                                                    <span>
                                                        {{ sc.assignment?.title }}
                                                        <span v-if="sc.assignment?.type_label" class="badge bg-secondary-subtle text-secondary-emphasis fw-normal ms-1">{{ sc.assignment.type_label }}</span>
                                                        <span v-if="sc.assignment?.subject" class="text-muted"> · {{ sc.assignment.subject }}</span>
                                                    </span>
                                                    <span class="text-muted text-nowrap">{{ scoreText(sc) }}</span>
                                                </li>
                                            </ul>
                                            <p v-if="studentGrades.scores_truncated" class="text-muted small mt-2 mb-0">Showing the most recent {{ studentGrades.scores_shown }}.</p>
                                        </template>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>

                <template v-else>
                    <button class="btn btn-link px-0 text-decoration-none mb-2" @click="openAssignment = null">
                        ← Assignments
                    </button>
                    <div class="d-flex align-items-start gap-2 mb-1">
                        <div class="fw-semibold flex-grow-1">{{ openAssignment.title }}</div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="startEdit(openAssignment)">
                            <i class="bi bi-pencil me-1"></i>Edit
                        </button>
                    </div>
                    <div class="text-muted small mb-1">
                        {{ openAssignment.scale === 'levels' ? 'Performance levels' : openAssignment.scale === 'simple' ? 'Excellent / Good / Needs work' : `Out of ${openAssignment.points_possible}` }}
                    </div>
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        <span v-if="openAssignment.subject" class="badge bg-primary-subtle text-primary-emphasis fw-normal">{{ openAssignment.subject }}</span>
                        <span v-if="openAssignment.type_label" class="badge bg-secondary-subtle text-secondary-emphasis fw-normal">{{ openAssignment.type_label }}</span>
                        <span v-if="weightNote(openAssignment, classWeights, weightingEnabled)" class="badge bg-light text-muted fw-normal">
                            {{ weightNote(openAssignment, classWeights, weightingEnabled) }}
                        </span>
                    </div>
                    <!-- The school's guide's own words, labelled as such: the guide
                         carries codes and a weekly focus, not the standard's wording. -->
                    <div v-if="openAssignment.standard_code || openAssignment.curriculum_focus" class="small mb-3">
                        <span class="fw-semibold">{{ openAssignment.standard_code || 'Standard' }}</span>
                        <span dir="auto"> {{ openAssignment.curriculum_focus }}</span>
                        <span class="text-muted"> · from the school's pacing guide<span v-if="openAssignment.curriculum_week_no">, week {{ openAssignment.curriculum_week_no }}</span></span>
                    </div>

                    <!-- THE KEY. Rendered from the payload, never hardcoded, so
                         these are the school's own words and a teacher choosing
                         between a 2 and a 3 for a real child can read what each
                         one actually means without leaving the screen. -->
                    <details v-if="openAssignment.scale === 'levels' && levelKey.length" class="mb-3">
                        <summary class="small text-primary" style="cursor:pointer">What do 4, 3, 2 and 1 mean?</summary>
                        <dl class="row small mt-2 mb-0">
                            <template v-for="l in levelKey" :key="l.level">
                                <dt class="col-sm-3 fw-semibold">{{ l.level }} — {{ l.short_label }}</dt>
                                <dd class="col-sm-9 text-muted">{{ l.description }}</dd>
                            </template>
                        </dl>
                    </details>

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
                            <!-- LEVELS: four buttons, because there are exactly four
                                 answers. A number box here would invite 2.5 and 0,
                                 neither of which the scale can express. Each button
                                 carries the school's own word, so a teacher is
                                 picking "Meets" rather than picking "3". -->
                            <div v-if="openAssignment.scale === 'levels'" class="d-flex align-items-center gap-1 flex-wrap">
                                <button v-for="l in levelKey" :key="l.level" type="button"
                                        class="btn btn-sm"
                                        :class="marks_g[s.membership_id]?.points_earned === l.level && marks_g[s.membership_id]?.status === 'scored'
                                            ? 'btn-primary' : 'btn-outline-primary'"
                                        :disabled="isExempt(s.membership_id)"
                                        :title="l.description"
                                        @click="setLevel(s.membership_id, l.level)">
                                    {{ l.level }} <span class="d-none d-md-inline">· {{ l.short_label }}</span>
                                </button>
                            </div>
                            <!-- EXCELLENT / GOOD / NEEDS WORK: three buttons, the
                                 words from the payload (simple_marks), stored 3/2/1.
                                 Tapping the lit one clears it, as on levels. -->
                            <div v-else-if="openAssignment.scale === 'simple'" class="d-flex align-items-center gap-1 flex-wrap">
                                <button v-for="m in simpleMarks" :key="m.value" type="button"
                                        class="btn btn-sm"
                                        :class="marks_g[s.membership_id]?.points_earned === m.value && marks_g[s.membership_id]?.status === 'scored'
                                            ? 'btn-primary' : 'btn-outline-primary'"
                                        :disabled="isExempt(s.membership_id)"
                                        @click="setLevel(s.membership_id, m.value)">
                                    {{ m.label }}
                                </button>
                            </div>
                            <!-- Typing a mark IS "scored". No third button, and the
                                 box is never disabled — the old design made you
                                 press S before you could type the thing S meant. -->
                            <div v-else class="d-flex align-items-center gap-1">
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

                    <p v-if="openAssignment.scale === 'levels'" class="text-muted small mb-2">
                        Choose a level to score a child.
                        <span class="text-danger-emphasis">Missing</span> is shown but left out of the average —
                        a 1 means “Needs Support”, which is not the same as work that was never handed in.
                        <span class="fw-semibold">Excused</span> does not count at all.
                        A child you leave blank is simply not marked yet.
                    </p>
                    <p v-else-if="openAssignment.scale === 'simple'" class="text-muted small mb-2">
                        Choose Excellent, Good or Needs work for each child. Families see the word, never a number.
                        <span class="text-danger-emphasis">Missing</span> is shown and counted on its own;
                        <span class="fw-semibold">Excused</span> does not count at all.
                        A child you leave blank is simply not marked yet.
                    </p>
                    <p v-else class="text-muted small mb-2">
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
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" id="vis-students" value="students"
                                       v-model="fileForm.visibility">
                                <label class="form-check-label small" for="vis-students">Specific students</label>
                            </div>
                        </div>

                        <!-- The roster, only when it is the answer to a question
                             that has been asked. The server re-reads every id
                             against this class, so a stale list here is a 422
                             and never a file addressed to the wrong child. -->
                        <div v-if="fileForm.visibility === 'students'" class="mt-2">
                            <label class="form-label small text-muted mb-1">Who is this for?</label>
                            <div class="border rounded p-2" style="max-height:12rem; overflow-y:auto">
                                <p v-if="!students.length" class="text-muted small mb-0">
                                    This class has no students on its roster yet.
                                </p>
                                <div v-for="s in students" :key="s.membership_id" class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           :id="`file-recipient-${s.membership_id}`"
                                           :value="s.membership_id" v-model="fileForm.recipientIds">
                                    <label class="form-check-label small" :for="`file-recipient-${s.membership_id}`">
                                        {{ studentName(s) }}
                                    </label>
                                </div>
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

                        <!-- Named at the moment of the choice here too, and with
                             the count, because "specific students" is the option
                             a teacher reaches for when the file names a child. -->
                        <div v-else-if="fileForm.visibility === 'students'" class="alert alert-warning small py-2 mt-2 mb-0">
                            <template v-if="fileForm.recipientIds.length">
                                Only the families of the {{ fileForm.recipientIds.length }}
                                {{ fileForm.recipientIds.length === 1 ? 'student' : 'students' }}
                                ticked above will be able to download this file. Nobody else in the class will see
                                that it exists.
                            </template>
                            <template v-else>Tick at least one student.</template>
                        </div>

                        <div class="d-flex align-items-center gap-2 mt-2">
                            <button class="btn btn-sm btn-success"
                                    :disabled="uploading || !fileForm.file || !fileForm.title.trim()
                                        || (fileForm.visibility === 'students' && !fileForm.recipientIds.length)"
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
                        <!-- WHO CAN SEE THIS, on every row. "Only you" has to be
                             a thing this list SAYS rather than a thing it leaves
                             out, and a targeted file has to say how many — a
                             file addressed to one child and a file addressed to
                             the whole class must never look alike here. -->
                        <span class="badge" :class="audienceBadgeClass(r)">{{ audienceLabel(r) }}</span>
                        <!-- Removing a file here also takes it out of every lesson plan
                             that lists it (T-004.1), so the row says how many do. -->
                        <span v-if="r.lesson_plan_count" class="badge bg-light text-muted">
                            In {{ r.lesson_plan_count }} lesson {{ r.lesson_plan_count === 1 ? 'plan' : 'plans' }}
                        </span>
                        <button class="btn btn-sm btn-outline-secondary" @click="downloadResource(r)">
                            <i class="bi bi-download"></i>
                        </button>
                        <button class="btn btn-sm btn-link text-danger" @click="deleteResource(r)">Remove</button>
                    </div>
                </div>
            </section>

            <!-- ====================================================== REPORTS -->
            <section v-else-if="activeTab === 'reports'">

                <!-- ------------------------------------------- the whole class -->
                <template v-if="!openCard">
                    <p class="text-muted small">
                        Report cards and progress reports for one quarter. Nothing here reaches a
                        family until you send it, and a card you have sent is locked until you take
                        it back.
                    </p>

                    <!-- The period. A bad value here never 422s — the server clamps it silently —
                         so the three selects are re-seeded from the period the server echoes back,
                         and that echo is printed underneath. Without it a mistyped quarter would
                         save into a different document and still answer 200. -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="row g-2 align-items-end">
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Report</label>
                                    <select v-model="reportType" class="form-select form-select-sm" style="width:11rem">
                                        <option value="report_card">Report Card</option>
                                        <option value="progress">Progress Report</option>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Quarter</label>
                                    <select v-model.number="reportTerm" class="form-select form-select-sm" style="width:7rem">
                                        <option v-for="t in [1, 2, 3, 4]" :key="t" :value="t">{{ t }}</option>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">School year</label>
                                    <select v-model="reportYear" class="form-select form-select-sm" style="width:9.5rem">
                                        <option v-for="y in schoolYearOptions" :key="y" :value="y">{{ y }}</option>
                                    </select>
                                </div>
                            </div>
                            <p v-if="reportPeriod" class="text-muted small mt-2 mb-0">
                                Showing {{ reportPeriod.type === 'progress' ? 'progress reports' : 'report cards' }}
                                for Quarter {{ reportPeriod.term }}, {{ reportPeriod.school_year }}.
                            </p>
                            <p v-if="reportsError" class="text-danger small mt-2 mb-0">{{ reportsError }}</p>
                        </div>
                    </div>

                    <div v-if="reportsLoading" class="text-center py-4">
                        <span class="spinner-border spinner-border-sm text-success"></span>
                    </div>
                    <p v-else-if="!reportRows.length" class="text-muted small">No students on this roster yet.</p>
                    <div v-else class="list-group">
                        <button v-for="r in reportRows" :key="r.membership_id" type="button"
                                class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                                @click="openReportCard(r)">
                            <PersonAvatar :avatar="r.contact?.avatar"
                                          :first-name="r.contact?.first_name" :last-name="r.contact?.last_name" :size="34" />
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-semibold small">{{ name(r.contact) }}</span>
                                    <span v-if="r.grade_label" class="badge bg-primary-subtle text-primary-emphasis fw-normal">
                                        {{ r.grade_label }}
                                    </span>
                                    <!--
                                        A child who has left is off every other
                                        screen in this class. They are still here
                                        because a card was already started for
                                        this period and the school owes the
                                        family that document — so the row says so
                                        rather than leaving a teacher to wonder
                                        why a name they stopped marking is back.
                                    -->
                                    <span v-if="r.left_on" class="badge bg-secondary-subtle text-secondary-emphasis fw-normal">
                                        Left {{ leftDay(r.left_on) }}
                                    </span>
                                </div>
                            </div>
                            <span class="badge" :class="rowStatus(r).cls">{{ rowStatus(r).text }}</span>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </button>
                    </div>
                </template>

                <!-- --------------------------------------------- one child's card -->
                <template v-else>
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                        <button class="btn btn-link px-0 text-decoration-none" @click="closeOpenCard">
                            ← All students
                        </button>
                        <!-- Deliberately available on a DRAFT too: proofreading a
                             card on paper before it goes home is the ordinary use,
                             and the office keeps a printed file. -->
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                :disabled="downloadingCard"
                                @click="downloadCard">
                            {{ downloadingCard ? 'Preparing…' : 'Download PDF' }}
                        </button>
                    </div>

                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="fw-semibold">{{ name(openCard.student?.contact) }}</span>
                        <span v-if="openCard.grade_label" class="badge bg-primary-subtle text-primary-emphasis fw-normal">
                            {{ openCard.grade_label }}
                        </span>
                    </div>
                    <div class="text-muted small mb-3">
                        {{ openCard.type_label }} · {{ openCard.period_label }}
                        · {{ cardAssessed }} of {{ cardTotal }} marked
                    </div>

                    <!-- PUBLISHED. The server's 422 is a backstop, not the mechanism: every
                         control below is disabled and Save is not rendered at all, so a teacher
                         never types into a form that is going to refuse them. -->
                    <div v-if="openCard.published" class="alert alert-info py-2 small d-flex flex-wrap align-items-center gap-2">
                        <span>Sent to the family on {{ when(openCard.published_at) }}. To change anything, take it back first.</span>
                        <button class="btn btn-sm btn-link text-danger p-0" :disabled="publishing" @click="unpublishReportCard">
                            {{ publishing ? 'Taking it back…' : 'Take it back' }}
                        </button>
                    </div>

                    <!-- The one error outlet for this view, OUTSIDE the published guard.
                         It used to live only inside the sticky save bar, which is hidden on a
                         published card — so a failed "Take it back" changed nothing on screen
                         and was indistinguishable from a no-op. -->
                    <p v-if="reportsError" class="text-danger small">{{ reportsError }}</p>

                    <!-- The attendance FROZEN at publication. Only shown once published: on a
                         draft these are null, and rendering a null as 0 would read as "never
                         absent". `present` already includes the late days, so it says so rather
                         than leaving a parent to work out whether the numbers double-count. -->
                    <div v-if="openCard.published" class="card border-0 bg-light mb-3">
                        <div class="card-body py-2 small">
                            Present {{ openCard.attendance.present }}
                            <span class="text-muted">(includes {{ openCard.attendance.late }} late)</span>
                            · Absent {{ openCard.attendance.absent }}
                        </div>
                    </div>

                    <!-- THE KEY, from the payload and never hardcoded — the school's own words,
                         readable at the moment a teacher is choosing between a 2 and a 3. -->
                    <details v-if="levelKey.length" class="mb-3">
                        <summary class="small text-primary" style="cursor:pointer">What do 4, 3, 2 and 1 mean?</summary>
                        <dl class="row small mt-2 mb-0">
                            <template v-for="l in levelKey" :key="l.level">
                                <dt class="col-sm-3 fw-semibold">{{ l.level }} — {{ l.short_label }}</dt>
                                <dd class="col-sm-9 text-muted">{{ l.description }}</dd>
                            </template>
                        </dl>
                    </details>

                    <div v-for="sub in openCard.subjects" :key="sub.subject" class="card border-0 shadow-sm mb-2">
                        <div class="card-header bg-white fw-semibold small">{{ sub.subject }}</div>
                        <div class="list-group list-group-flush">
                            <div v-for="m in sub.criteria" :key="m.id"
                                 class="list-group-item d-flex align-items-center gap-3 flex-wrap"
                                 :class="isDirty(m.id) ? 'border-start border-warning border-3' : ''">
                                <div class="flex-grow-1" style="min-width:12rem">
                                    <span class="small">{{ m.criterion }}</span>
                                </div>

                                <!-- Four levels and a clear. The fifth button is not decoration:
                                     "not assessed" is a real thing to say about a child who joined
                                     in week eight, and without it the only way back to unmarked
                                     would be reloading the page. Number keys do the same while the
                                     group has focus — sixteen children times twenty-three criteria
                                     is a keyboard job. -->
                                <div class="btn-group btn-group-sm flex-shrink-0" @keydown="onLevelKey($event, m.id)">
                                    <button v-for="l in levelKey" :key="l.level" type="button" class="btn"
                                            :class="draft[m.id]?.level === l.level ? 'btn-primary' : 'btn-outline-primary'"
                                            :disabled="openCard.published" :title="l.description"
                                            @click="setMarkLevel(m.id, l.level)">{{ l.level }}</button>
                                    <button type="button" class="btn"
                                            :class="draft[m.id]?.level === null ? 'btn-secondary' : 'btn-outline-secondary'"
                                            :disabled="openCard.published" title="Not assessed"
                                            @click="clearMarkLevel(m.id)">—</button>
                                </div>

                                <input v-if="draft[m.id]" v-model="draft[m.id].comment" type="text" maxlength="1000"
                                       class="form-control form-control-sm" style="width:18rem"
                                       :disabled="openCard.published" placeholder="Optional note"
                                       @input="onCardEdited">
                            </div>
                        </div>
                    </div>

                    <!-- LEARNING BEHAVIOURS, deliberately their own card BELOW the subjects. The
                         server keeps them beside `subjects` rather than inside one because they
                         are how a child works, not what a child knows; folding them into a subject
                         is exactly what that separation exists to prevent. -->
                    <div v-if="openCard.learning_behaviours.length" class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold small">Learning behaviours</div>
                        <div class="list-group list-group-flush">
                            <div v-for="m in openCard.learning_behaviours" :key="m.id"
                                 class="list-group-item d-flex align-items-center gap-3 flex-wrap"
                                 :class="isDirty(m.id) ? 'border-start border-warning border-3' : ''">
                                <div class="flex-grow-1" style="min-width:12rem">
                                    <span class="small">{{ m.criterion }}</span>
                                </div>
                                <div class="btn-group btn-group-sm flex-shrink-0" @keydown="onLevelKey($event, m.id)">
                                    <button v-for="l in levelKey" :key="l.level" type="button" class="btn"
                                            :class="draft[m.id]?.level === l.level ? 'btn-primary' : 'btn-outline-primary'"
                                            :disabled="openCard.published" :title="l.description"
                                            @click="setMarkLevel(m.id, l.level)">{{ l.level }}</button>
                                    <button type="button" class="btn"
                                            :class="draft[m.id]?.level === null ? 'btn-secondary' : 'btn-outline-secondary'"
                                            :disabled="openCard.published" title="Not assessed"
                                            @click="clearMarkLevel(m.id)">—</button>
                                </div>
                                <input v-if="draft[m.id]" v-model="draft[m.id].comment" type="text" maxlength="1000"
                                       class="form-control form-control-sm" style="width:18rem"
                                       :disabled="openCard.published" placeholder="Optional note"
                                       @input="onCardEdited">
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <label class="form-label small text-muted mb-1">Comment to the family</label>
                            <textarea v-model="teacherComment" rows="4" maxlength="4000"
                                      class="form-control form-control-sm" :disabled="openCard.published"
                                      placeholder="What has gone well this quarter, and what to work on next."
                                      @input="onCardEdited"></textarea>
                            <p class="text-muted small mt-1 mb-0">The family reads this. Write about this child only.</p>
                        </div>
                    </div>

                    <!-- SEND. Its own card rather than a button in the save row: it changes who
                         can see the document, and it freezes the attendance figures at this
                         moment. Disabled while anything is unsaved, so a teacher cannot send a
                         card that does not yet say what is on their screen. -->
                    <div v-if="!openCard.published" class="card border shadow-none mb-3">
                        <div class="card-body">
                            <div class="row g-2 align-items-end">
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">Attendance from</label>
                                    <input v-model="publishFrom" type="date" class="form-control form-control-sm" style="width:10rem">
                                </div>
                                <div class="col-6 col-sm-auto">
                                    <label class="form-label small text-muted mb-1">to</label>
                                    <input v-model="publishTo" type="date" class="form-control form-control-sm" style="width:10rem">
                                </div>
                                <div class="col-auto">
                                    <button class="btn btn-sm btn-outline-success"
                                            :disabled="publishing || hasUnsaved || !cardAssessed"
                                            @click="publishReportCard">
                                        {{ publishing ? 'Sending…' : 'Send to the family' }}
                                    </button>
                                </div>
                            </div>
                            <p class="text-muted small mt-2 mb-0">
                                Leave the dates empty for the whole year so far. Sending freezes the
                                attendance figures and locks the card; taking it back clears them again.
                                <span v-if="hasUnsaved" class="text-danger-emphasis">Save your changes first.</span>
                                <span v-else-if="!cardAssessed" class="text-danger-emphasis">Mark at least one criterion first.</span>
                            </p>
                        </div>
                    </div>

                    <!-- Sticks to the bottom of the viewport. With twenty-three criteria the Save
                         button is otherwise a full scroll away from the mark just changed, and the
                         unsaved count is the only thing between a teacher and losing an afternoon. -->
                    <div v-if="!openCard.published || hasUnsaved"
                         class="position-sticky bottom-0 bg-white border-top pt-2 pb-2 d-flex align-items-center gap-2 flex-wrap">
                        <!-- `|| hasUnsaved` is not belt-and-braces. If the card is published
                             from elsewhere while a teacher is marking, the save is refused and
                             the card re-reads as published WITH their typing preserved — and a
                             bar hidden on `published` would then strand them: no Save, and a
                             back button that silently refuses to leave. -->
                        <template v-if="openCard.published">
                            <span class="small text-danger-emphasis">
                                This card was sent to the family while you were working.
                                {{ unsavedCount }} change{{ unsavedCount === 1 ? '' : 's' }} of yours
                                {{ unsavedCount === 1 ? 'is' : 'are' }} still here — take it back to save
                                {{ unsavedCount === 1 ? 'it' : 'them' }}.
                            </span>
                            <button class="btn btn-sm btn-link text-danger" @click="discardAndClose">Discard mine</button>
                        </template>
                        <template v-else-if="!leaveWarned">
                            <button class="btn btn-sm btn-success" :disabled="savingCard || !hasUnsaved" @click="saveReportCard">
                                {{ savingCard ? 'Saving…' : 'Save' }}
                            </button>
                            <span v-if="hasUnsaved" class="text-danger-emphasis small">
                                {{ unsavedCount }} change{{ unsavedCount === 1 ? '' : 's' }} not saved
                            </span>
                            <span v-if="cardSaved" class="text-success small"><i class="bi bi-check-circle me-1"></i>Saved</span>
                        </template>
                        <template v-else>
                            <span class="small">{{ unsavedCount }} change{{ unsavedCount === 1 ? '' : 's' }} not saved.</span>
                            <button class="btn btn-sm btn-success" :disabled="savingCard" @click="saveAndClose">Save and close</button>
                            <button class="btn btn-sm btn-link text-danger" @click="discardAndClose">Discard</button>
                        </template>
                        <span v-if="reportsError" class="text-danger small">{{ reportsError }}</span>
                    </div>
                </template>
            </section>

            <!-- ============================== CLASS STORE (T-003.4, only where switched on) -->
            <section v-else-if="activeTab === 'store' && group.class_store === true">
                <TeacherClassStore :base="base" />
            </section>
            <p v-else-if="classSubjects.enabled.value && activeTab === 'subject'" class="text-muted">There is nothing here yet.</p>
            </ClassNavigation>
        </template>

        <!-- The student's sheet (Roster tab): name, grade, age, and the avatar
             picker, which used to be a button on the row. -->
        <TeacherStudentSheet v-if="sheetFor" :student="sheetFor" :masjid-id="masjidId" :base="base"
                             :is-class="group?.kind === 'class'"
                             @close="sheetFor = null" @avatar-saved="onAvatarSaved" />
    </div>
</template>

<script setup lang="ts">
import TeacherApiService, { rowsOf } from '@/core/services/TeacherApiService';
import { apiErrorText, uploadErrorText } from '@/core/services/ApiErrors';
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import TeacherPhoto from '@/views/teacher/TeacherPhoto.vue';
import TeacherClassStore from '@/views/teacher/TeacherClassStore.vue';
import MessageSignals from '@/components/common/MessageSignals.vue';
import EditableMessageBody from '@/components/common/EditableMessageBody.vue';
import { messageHasMedia, replaceMessage } from '@/core/helpers/messageEdit';
import StorySeenLine from '@/components/common/StorySeenLine.vue';
import StoryEditForm from '@/components/common/StoryEditForm.vue';
import { useStoryEdit } from '@/composables/useStoryEdit';
import { canEditStory, editedMarker } from '@/core/helpers/storyEdit';
// "Send later" and the Scheduled list (T-002.4), shared with the office's tabs.
import SendLaterField from '@/components/common/SendLaterField.vue';
import ScheduledItems from '@/components/common/ScheduledItems.vue';
import { useSendLater } from '@/composables/useSendLater';
import { messageRow, storyRow, type ScheduledRow } from '@/core/helpers/scheduledSend';
import GroupMediaPicker from '@/components/partials/GroupMediaPicker.vue';
import { isVideoFile, pickerLimits } from '@/core/helpers/mediaPick';
import TeacherStudentSheet from '@/views/teacher/TeacherStudentSheet.vue';
import { hifzKindLabel, hifzQualityLabel } from '@/core/helpers/hifzLabels';
import { hifzDayLabel, hifzDayOf, hifzDayToSend } from '@/core/helpers/hifzDay';
import { ageLabel } from '@/core/helpers/studentAge';
import StandardPicker from '@/components/teacher/StandardPicker.vue';
import SurahPicker from '@/components/teacher/SurahPicker.vue';
import { SchoolDayStatus, formatSchoolDay } from '@/core/types/data/masjid-related/SchoolCalendar';
import { awardPointsLabel, pickerFrom, withSkillInserted } from '@/core/helpers/behaviorSkills';
import { isWeekly, pointsHeadline, signedPoints, weekFromQuery, weekRangeLabel } from '@/core/helpers/pointsWeek';
import { letterIdOfTile, letterRuns, toggledTileKey } from '@/core/helpers/letterRuns';
import { islamicIntegration, outcomeFill, weekOutsideGuide } from '@/core/helpers/lessonPlanPrefill';
import {
    averageLines, blankWorkForm, effectiveWeight, fencedNote, firstFieldError, isCombinedGuideColumn, isUntyped, percentText, subjectLine, untypedInWork, untypedListNote, untypedNote,
    mayChangeWeights, NOT_AVERAGED, SIMPLE_SCALE, weightNote, weightsFormFrom, weightsRequest, workFormFrom, workFormReady, workRequest,
} from '@/core/helpers/gradebook';
import {
    MAX_PLAN_FILES, attachmentIds, canSavePlan, copyRequest, formTicket, jumpTarget, pickPlan, planAlreadyGone, planDeleteUrl, planFilesFull as planFilesFullOf,
    planLabel, plansOn, planSaveRequest, subjectClash, subjectKey, takenSubjectKeys, unattachedFiles, withAttachment, withoutAttachment,
} from '@/core/helpers/lessonPlans';
import {
    FOCUS_REFRESH_GAP_MS, MESSAGE_PAGE_SIZE, afterOpening, focusRefreshDue, newChip, openWholeThread,
    threadNewLabel, unreadNumber, unreadPill, unreadSpoken,
} from '@/core/helpers/threadUnread';
import { useAuthStore } from '@/stores/authStore';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import ClassNavigation from '@/components/classes/ClassNavigation.vue';
import { useClassSubjects } from '@/composables/useClassSubjects';

type TabKey = 'roster' | 'attendance' | 'letters' | 'points' | 'hifz' | 'story' | 'messages'
    | 'lessons' | 'grades' | 'files' | 'reports' | 'store' | 'subject';

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
// `?tab=points` is what the weekly class-summary email links to (T-003.3). The one tab a
// link may open, so an arbitrary query value can never select a tab the screen hides.
const activeTab = ref<TabKey>(route.query.tab === 'points' ? 'points' : 'roster');
// `?week=` (with `?tab=points`) names the week the email reported, so the Points tab opens on
// that week and not on the one in progress; null (or an invalid value) is the current week.
const linkedPointsWeek = route.query.tab === 'points' ? weekFromQuery(route.query.week) : null;

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
    // NOT bi-clipboard-data: it sits directly under Grades' bi-clipboard-check
    // in the same dropdown, and two clipboards read as one entry.
    { key: 'reports', label: 'Reports', icon: 'bi-file-earmark-bar-graph' },
    { key: 'files', label: 'Files', icon: 'bi-folder2-open' },
    // Manara Bucks (T-003.4). Listed here but SHOWN only for a school that has the store on:
    // the class payload carries `class_store: true` then and no key at all otherwise.
    { key: 'store', label: 'Class Store', icon: 'bi-shop' },
];

const shownMoreTabs = computed(() => moreTabs.filter((t) => t.key !== 'store' || group.value?.class_store === true));

const activeMoreTab = computed(() => moreTabs.find((t) => t.key === activeTab.value) ?? null);

/**
 * The tab a SUBJECT owns (owner, 2026-09-21): Arabic owns the letters, Qur'an owns
 * hifdh, and Islamic Studies owns neither. Every other tab belongs to whoever
 * teaches the class. `group.my_subjects` is null for a teacher who teaches
 * everything — every assignment before subjects existed, and every full-time
 * teacher — so for them nothing is hidden.
 *
 * This only hides; the server refuses the same routes (`teacher.teaches:`), which
 * is the actual boundary. A tab shown here that the server refused would be a bug
 * in this list, not a hole.
 */
const SUBJECT_OF_TAB: Partial<Record<TabKey, string>> = { letters: 'arabic', hifz: 'quran' };
const teachesTab = (key: TabKey): boolean => {
    const needs = SUBJECT_OF_TAB[key];
    const mine = group.value?.my_subjects;
    return !needs || !Array.isArray(mine) || mine.length === 0 || mine.includes(needs);
};
const visibleTabs = computed(() => tabs.filter((t) => teachesTab(t.key)));

const moreOpen = ref(false);

// Close on any click that is not inside the dropdown itself (the trigger stops
// propagation), and on Escape.
const closeMore = () => { moreOpen.value = false; };
const closeMoreOnEscape = (e: KeyboardEvent) => { if (e.key === 'Escape') moreOpen.value = false; };

/**
 * The browser's own "leave site?" prompt, armed only while a report card has
 * unsaved marks.
 *
 * The in-app back link routes through `closeOpenCard`, but a refresh, a closed
 * tab or the browser's back button do not, and there is nowhere else the draft
 * lives — an afternoon of marking is in memory until Save. preventDefault() is
 * what actually arms the dialog in current browsers; the returnValue assignment
 * is the legacy spelling some still want.
 */
const warnOnUnload = (e: BeforeUnloadEvent) => {
    if (!hasUnsaved.value) return;
    e.preventDefault();
    e.returnValue = '';
};

onMounted(() => {
    document.addEventListener('click', closeMore);
    document.addEventListener('keydown', closeMoreOnEscape);
    window.addEventListener('beforeunload', warnOnUnload);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', closeMore);
    document.removeEventListener('keydown', closeMoreOnEscape);
    window.removeEventListener('beforeunload', warnOnUnload);
});

const students = computed<any[]>(() => group.value?.students ?? []);
// The student whose sheet is open (Roster tab), or null.
const sheetFor = ref<any>(null);

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

/**
 * What the school calendar says about the register's day (`data.school_day`).
 * Null when the server sent none — an org without a calendar, or a server that
 * predates it — which behaves exactly as the register always has.
 */
const schoolDay = ref<SchoolDayStatus | null>(null);
const attClosed = computed(() => !!schoolDay.value?.closed);
const attDateLabel = computed(() => formatSchoolDay(attDate.value, 'en-US'));

const offDayNote = computed(() => {
    const day = schoolDay.value;
    if (!day || !day.has_calendar || day.closed || day.meeting_day) return '';
    return day.in_year
        ? "This isn't one of the school's meeting days on the calendar. You can still take a register if the class met."
        : 'This day is outside the school year on the calendar. You can still take a register if the class met.';
});

const loadAttendance = async () => {
    attLoading.value = true;
    attError.value = '';
    attSaved.value = false;
    try {
        const res = await TeacherApiService.get(`${base.value}/attendance?date=${attDate.value}`);
        const data = res.data?.data ?? {};
        const day = data.school_day;
        schoolDay.value = day && typeof day === 'object'
            ? {
                has_calendar: !!day.has_calendar,
                in_year: !!day.in_year,
                meeting_day: !!day.meeting_day,
                closed: !!day.closed,
                reason: day.reason ? String(day.reason) : null,
            }
            : null;
        attTaken.value = !!data.taken;
        const next: Record<number, string> = {};
        for (const s of data.students ?? []) {
            // Only a REAL mark seeds the form. An unmarked child stays unmarked,
            // so opening the tab can never silently record a class as present.
            if (s.status) next[s.membership_id] = s.status;
        }
        marks.value = next;
    } catch {
        schoolDay.value = null;
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
        // The server's own words: a closed day is refused as
        // 422 {status:'failed', data:{session_date:["There was no school on …"]}}.
        const message = apiErrorText(e, 'Could not save the register.');
        attError.value = message;

        // A refusal about the DAY means the calendar changed under this screen
        // (the office closed it after the register opened). Re-read it, so the
        // "No school" banner replaces a register that can no longer be kept.
        const bag = e?.response?.data?.data;
        if (e?.response?.status === 422 && bag && typeof bag === 'object' && 'session_date' in bag) {
            attSaving.value = false;
            await loadAttendance();
            // loadAttendance clears the error. Keep the reason unless the banner now says it.
            if (!attClosed.value) attError.value = message;
        }
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
const planView = ref<'day' | 'week'>('day');
const plans = ref<any[]>([]);
/**
 * The plan open in the day view: one per subject per day, so the day holds a
 * list and this says which. NULL is a new plan — the empty form for another
 * subject. Every change to it is followed by syncPlanForm() (selectPlan, the
 * planDate watcher, loadLessonPlans), so everything keyed to "the plan on
 * screen" (the guide's autoFilled record, the standard typeahead, the
 * curriculum lists) is reset whenever the plan changes, not only the day.
 */
const planId = ref<number | null>(null);
/**
 * Which form is on screen, replaced by every syncPlanForm(). A guide fill or a
 * save still in flight when the teacher opens another plan must not land in
 * that plan: each takes a ticket before its await and checks it after.
 */
const planForms = formTicket();
const planSaving = ref(false);
const planDeleting = ref(false);
/** The Lessons tab has loaded once, so coming back to it keeps the open plan's draft. */
let lessonsLoaded = false;
/** Every week load bumps it; an older week's answer landing after a newer one is dropped. */
let plansSeq = 0;
/**
 * A dropped load that was asked to re-sync (a week change, a removal) leaves
 * the re-sync owed to the load that replaced it — or a week changed during a
 * save would open no plan at all.
 */
let resyncOwed = false;
/** The form as syncPlanForm last loaded it, so a reload can tell a draft from an untouched plan. */
let planSnapshot = '';
const planDirty = () => JSON.stringify(planForm.value) !== planSnapshot;
const planSaved = ref(false);
const planError = ref('');

// ---------- files under Activities (T-004.1) ----------
const planFilePick = ref<string | number>('');
const planFileBusy = ref(false);
const planFileError = ref('');
const planFilesFull = computed(() => planFilesFullOf(planForm.value.attachments));
/** This class's files that the open plan does not list yet. */
const unattachedResources = computed(() => unattachedFiles(resources.value, planForm.value.attachments));

const attachPickedFile = () => {
    const picked = resources.value.find((r: any) => Number(r.id) === Number(planFilePick.value));
    planFilePick.value = '';
    if (picked) planForm.value.attachments = [...withAttachment(planForm.value.attachments, picked, MAX_PLAN_FILES)];
};

const detachPlanFile = (id: number) => {
    planForm.value.attachments = withoutAttachment(planForm.value.attachments, id);
};

/**
 * Upload a file for this plan: the class's ordinary Files upload, staff-only (the
 * default), then listed on the form. It is attached to the PLAN only when the
 * plan is saved; until then the file is simply in the class's Files, private.
 */
const uploadPlanFile = async (e: Event) => {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || planFilesFull.value) return;

    planFileBusy.value = true;
    planFileError.value = '';
    try {
        const form = new FormData();
        form.append('file', file);
        form.append('title', file.name.replace(/\.[^.]+$/, '').slice(0, 200) || 'Lesson file');
        form.append('visibility', 'staff');
        const res = await TeacherApiService.postForm(`${base.value}/resources`, form);
        const created = res.data?.data;
        if (created?.id) {
            planForm.value.attachments = [...withAttachment(planForm.value.attachments, created, MAX_PLAN_FILES)];
            await loadResources();
        }
    } catch (err: any) {
        planFileError.value = err?.response?.data?.data?.file?.[0]
            ?? err?.response?.data?.data?.title?.[0]
            ?? 'That file could not be uploaded.';
    } finally {
        planFileBusy.value = false;
    }
};

/** The template's teaching-method checkboxes. Values mirror LessonPlan::TEACHING_METHODS. */
const TEACHING_METHODS = [
    { value: 'modeling', label: 'Modeling' },
    { value: 'guided_practice', label: 'Guided Practice' },
    { value: 'cooperative_learning', label: 'Cooperative Learning' },
    { value: 'inquiry_discussion', label: 'Inquiry / Discussion' },
    { value: 'hands_on_activity', label: 'Hands-On Activity' },
    { value: 'storytelling', label: 'Storytelling' },
    { value: 'other', label: 'Other' },
];

const emptyPlan = () => ({
    title: '', body: '',
    subject: '', grade_label: '', curriculum_week_no: null as number | null,
    standard_code: '', standard_description: '',
    objective: '', learning_outcomes: [] as string[],
    differentiation_support: '', differentiation_extension: '',
    differentiation_learning_styles: '', differentiation_ell_aal: '', differentiation_sen: '',
    cross_integration_subject: '', cross_integration_islamic: '', cross_integration_stem: '',
    teaching_methods: [] as string[], teaching_methods_other: '', teaching_aids: '',
    assessment_formative: '', assessment_exit_ticket: '',
    reflection_worked: '', reflection_improve: '',
    prefill_source: '',
    // The files listed under Activities: display objects from the plan payload
    // (id, title, name, size). Saved as `resource_ids`; see savePlan.
    attachments: [] as any[],
});

const planForm = ref<any>(emptyPlan());

// The school's template, in its own order. The hint on each header is the
// guiding question from the school's own poster version.
const planSections = [
    { key: 'standard', label: 'Standard', hint: 'What standard am I teaching?', fields: [
        { key: 'standard_code', label: 'Code', rows: 1, placeholder: 'e.g. K.CC.A.1' },
        { key: 'standard_description', label: 'Description', rows: 2 },
    ] },
    { key: 'objective', label: 'Objective & Outcomes', hint: 'What will students be able to do?', fields: [
        { key: 'objective', label: 'Objective', rows: 2 },
        { key: 'learning_outcomes', label: 'Learning outcomes' },
    ] },
    { key: 'differentiation', label: 'Differentiation', hint: 'How will I support all learners?', fields: [
        { key: 'differentiation_support', label: 'Support for struggling learners' },
        { key: 'differentiation_extension', label: 'Extension for advanced learners' },
        { key: 'differentiation_learning_styles', label: 'Learning-style adjustments' },
        { key: 'differentiation_ell_aal', label: 'ELL / AAL' },
        { key: 'differentiation_sen', label: 'SEN' },
    ] },
    { key: 'cross', label: 'Cross-Integration', hint: 'How does this connect to other subjects, Islamic values, STEM?', fields: [
        { key: 'cross_integration_subject', label: 'Subject integration' },
        { key: 'cross_integration_islamic', label: 'Islamic integration' },
        { key: 'cross_integration_stem', label: 'STEM' },
    ] },
    { key: 'teaching', label: 'Teaching Methods & Aids', hint: 'How will I teach it? What do I need?', fields: [
        { key: 'teaching_methods', label: 'Methods' },
        { key: 'teaching_aids', label: 'Teaching aids', rows: 2 },
    ] },
    { key: 'assessment', label: 'Assessment', hint: 'How will I measure learning?', fields: [
        { key: 'assessment_exit_ticket', label: 'Exit ticket / final task', rows: 2 },
    ] },
    { key: 'reflection', label: 'Reflection', hint: 'What will I adjust next time?', fields: [
        { key: 'reflection_worked', label: 'What worked well' },
        { key: 'reflection_improve', label: 'What needs improvement' },
    ] },
];

// The organisation's shorter plan (`short_lesson_plan`, SuperAdmin-set): the
// fields its form leaves out, from the lesson-plans payload. Empty everywhere
// else. A section with nothing left in it is not drawn at all.
const planHidden = ref<Set<string>>(new Set());
const visiblePlanSections = computed(() => planSections
    .map((sec) => ({ ...sec, fields: sec.fields.filter((f) => !planHidden.value.has(f.key)) }))
    .filter((sec) => sec.fields.length > 0));
// The weekdays the school meets on (0 = Sunday), from its school calendar; null
// without one, and then the week grid is Monday to Friday as it always was.
const planWeekdays = ref<number[] | null>(null);

const planOpen = ref<Record<string, boolean>>({});
const togglePlanSection = (key: string) => { planOpen.value[key] = !planOpen.value[key]; };

/** Does this section hold anything? Drives the dot on its header. */
const sectionFilled = (sec: any): boolean => sec.fields.some((f: any) => {
    const v = planForm.value[f.key];
    return Array.isArray(v) ? v.filter(Boolean).length > 0 : !!(v && String(v).trim());
});

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

/** The school week. The template's grid is Monday to Friday, or the calendar's meeting days. */
const weekdaysOnly = computed(() => planWeekdays.value
    ? weekDays.value.filter((_, i) => planWeekdays.value!.includes(i))
    : weekDays.value.slice(1, 6));

const weekLabel = computed(() => {
    const days = weekDays.value;
    return days.length ? `${days[0].label} — ${days[6].label}` : '';
});

const planDayLabel = computed(() =>
    new Date(planDate.value + 'T00:00:00')
        .toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'short' }));

/** Every plan on one day, general first, then by subject. */
const dayPlansOn = (iso: string) => plansOn(plans.value, iso);
const dayPlans = computed(() => dayPlansOn(planDate.value));
const selectedPlan = computed(() =>
    planId.value === null ? null : (plans.value.find((p) => p.id === planId.value) ?? null));

/**
 * The subjects the day's OTHER plans already hold, so the picker does not offer
 * one twice; and the plan the form's subject would collide with, so the form
 * says so before Save rather than after. The server refuses a clash either way.
 */
const takenSubjects = computed(() => takenSubjectKeys(plans.value, planDate.value, planId.value));
const planClash = computed(() =>
    subjectClash(plans.value, planDate.value, planForm.value.subject, planId.value));

/** Open one plan of the selected day, or NULL for a new one. */
const selectPlan = (id: number | null) => {
    planId.value = id;
    syncPlanForm();
};

/**
 * A plan for another subject on the same day. The grade carries over — a
 * teacher writing Science after Math is usually still teaching the same grade —
 * and nothing else does: the subject is the one thing that must differ.
 */
const addSubjectPlan = () => {
    const grade = planForm.value.grade_label;
    selectPlan(null);
    if (grade) {
        planForm.value.grade_label = grade;
        loadCurriculum(grade);
    }
};

/** From the week grid to one plan (or the day's first) in the day view. */
const jumpToDay = (iso: string, id: number | null = null) => {
    planView.value = 'day';
    if (planDate.value === iso) {
        // Back to the day already open: stay on the plan being written unless
        // another one was asked for — see jumpTarget.
        const next = jumpTarget(plans.value, iso, id, planId.value);
        if (next !== undefined) selectPlan(next);
        return;
    }
    // The planDate watcher opens it: it keeps planId when that plan is on the
    // new day, and falls back to the day's first plan when it is not.
    planId.value = id;
    planDate.value = iso;
};

const toggleMethod = (value: string) => {
    const list: string[] = planForm.value.teaching_methods;
    const i = list.indexOf(value);
    if (i === -1) list.push(value); else list.splice(i, 1);
};

// ---------- the school's pacing guide ----------
const curriculum = ref<{ grades: string[]; subjects: string[]; weeks: any[] }>({
    grades: [], subjects: [], weeks: [],
});
/**
 * The grade and subject the subject and week lists were loaded for. A week
 * picked from a list left over from another day's plan must not fill this one.
 */
const curriculumFor = ref({ grade: '', subject: '' });
let curriculumSeq = 0;
const prefilling = ref(false);
const copying = ref(false);
/** A week was picked and every field it would fill already held the teacher's words. */
const prefillKept = ref(false);

const canPrefill = computed(() =>
    !!planForm.value.grade_label && !!planForm.value.subject && !!planForm.value.curriculum_week_no);

const loadCurriculum = async (grade?: string, subject?: string) => {
    const seq = ++curriculumSeq;
    try {
        const q = new URLSearchParams();
        if (grade) q.set('grade', grade);
        if (subject) q.set('subject', subject);
        // Names the class so the server can limit the subject list to what THIS
        // teacher teaches here (the same fence the plan's save enforces).
        q.set('group_id', groupId.value);
        const res = await TeacherApiService.get(
            `/api/teacher/masjids/${masjidId.value}/curriculum${q.toString() ? '?' + q : ''}`
        );
        if (seq !== curriculumSeq) return;
        const d = res.data?.data ?? {};
        curriculum.value = {
            grades: d.grades ?? [],
            subjects: d.subjects ?? [],
            weeks: d.weeks ?? [],
        };
        curriculumFor.value = { grade: grade ?? '', subject: subject ?? '' };
        weekOther.value = weekOutsideGuide(planForm.value.curriculum_week_no, curriculum.value.weeks);
        // A subject shown in the free-text box that this grade's guide does
        // list goes back to the picker (the watch below only ever sets it).
        const shown = planForm.value.subject;
        if (subjectOther.value && shown && curriculum.value.subjects.includes(shown)) {
            subjectOther.value = false;
        }
    } catch {
        if (seq !== curriculumSeq) return;
        // No guide imported for this school: the form falls back to free text.
        curriculum.value = { grades: [], subjects: [], weeks: [] };
        curriculumFor.value = { grade: '', subject: '' };
    }
};

const onGradeChange = async () => {
    cancelPrefill();
    planForm.value.subject = '';
    planForm.value.curriculum_week_no = null;
    await loadCurriculum(planForm.value.grade_label);
};

/**
 * The sentinel for "not in the guide". A literal that no real subject can be —
 * an empty string already means "none chosen", and any readable word could
 * collide with a subject a school actually imports.
 */
const SUBJECT_OTHER = '__other__';

/** True while the teacher is typing a subject the guide does not list. */
const subjectOther = ref(false);

const onSubjectChange = async () => {
    cancelPrefill();
    planForm.value.curriculum_week_no = null;
    await loadCurriculum(planForm.value.grade_label, planForm.value.subject);
};

/** Picking "Other…" clears the box rather than storing the sentinel. */
const onSubjectPick = async () => {
    if (planForm.value.subject === SUBJECT_OTHER) {
        cancelPrefill();
        planForm.value.subject = '';
        planForm.value.curriculum_week_no = null;
        subjectOther.value = true;
        // The previous subject's weeks are not this subject's, and a load
        // still in flight for it must not bring them back.
        curriculumSeq++;
        curriculum.value = { ...curriculum.value, weeks: [] };
        curriculumFor.value = { grade: planForm.value.grade_label || '', subject: '' };
        return;
    }
    await onSubjectChange();
};

const useGuideSubjects = async () => {
    subjectOther.value = false;
    planForm.value.subject = '';
    await onSubjectChange();
};

/**
 * A plan already saved against a subject the guide does not carry — an older
 * plan, or one written before the guide was imported — opens in the text box
 * rather than silently losing its subject to a select that has no such option.
 */
watch(
    () => [planForm.value.subject, curriculum.value.subjects] as const,
    ([subject, subjects]) => {
        if (subject && subjects.length && !subjects.includes(subject)) {
            subjectOther.value = true;
        }
    },
    { immediate: true }
);

/**
 * What the guide last wrote into each field. A field still holding exactly that
 * is the tool's to replace when the teacher picks another week or standard; a
 * field the teacher wrote or edited is theirs and is never touched. Cleared
 * whenever the form is reloaded, so a SAVED plan is always the teacher's.
 */
const autoFilled = ref<Record<string, string>>({});

/**
 * Write one field from the guide under that rule. `typedIn` is the field the
 * teacher was typing a search into: it is replaced even though they wrote it,
 * because what they wrote there was the question, not the answer. So is search
 * text they typed and left without picking ("fractions" in the Code box) — see
 * stdLeft. True when the field changed — written, or emptied.
 */
const autoFill = (k: string, v: unknown, typedIn = ''): boolean => {
    if (planHidden.value.has(k)) return false;
    const value = v == null ? '' : String(v);
    const current = String(planForm.value[k] ?? '').trim();
    const ours = k === typedIn || current === ''
        || current === (autoFilled.value[k] ?? '').trim()
        || current === stdLeft.value[k];
    if (!ours) return false;
    planForm.value[k] = value;
    autoFilled.value[k] = value;
    delete stdLeft.value[k];
    return value !== current;
};

/**
 * The school's Learning Outcome onto the plan's outcomes list, under the same
 * rule as autoFill (see outcomeFill): only where the teacher has written none or
 * the guide wrote the only one there; a week with no outcome empties the one
 * the guide wrote. True when the list changed.
 */
const autoFillOutcome = (outcome: unknown): boolean => {
    if (planHidden.value.has('learning_outcomes')) return false;
    const next = outcomeFill(planForm.value.learning_outcomes, outcome == null ? null : String(outcome),
        autoFilled.value.learning_outcomes);
    if (!next) return false;
    planForm.value.learning_outcomes = next;
    autoFilled.value.learning_outcomes = next[0] ?? '';
    return true;
};

/** Open every section the guide just wrote into, so a fill is never hidden. */
const openFilledSections = () => {
    for (const sec of visiblePlanSections.value) {
        if (sectionFilled(sec)) planOpen.value[sec.key] = true;
    }
};

/** The week select's last option: swaps in the number input for a week the list lacks. */
const WEEK_OTHER = '__other_week__';

/** True while the teacher types a week the guide's list does not carry. */
const weekOther = ref(false);

const useGuideWeeks = () => {
    weekOther.value = false;
    planForm.value.curriculum_week_no = null;
};

/**
 * Picking a week fills the plan — teachers read a week picker as the fill, and
 * a separate button they did not press left them typing out the guide. Only
 * empty fields and the guide's own earlier writes change; the button stays for
 * refilling after a teacher clears something.
 */
const onWeekPick = () => {
    if ((planForm.value.curriculum_week_no as unknown) === WEEK_OTHER) {
        cancelPrefill();
        planForm.value.curriculum_week_no = null;
        weekOther.value = true;
        return;
    }
    const f = planForm.value;
    // Only from the list loaded for this grade and subject.
    if (curriculumFor.value.grade !== (f.grade_label || '')
        || curriculumFor.value.subject !== (f.subject || '')) return;
    if (canPrefill.value) prefillFromGuide(true);
};

/**
 * Every prefill asks for one grade, subject, week and day. Anything that moves
 * the form off them — another week, subject, grade or day, or a pick from the
 * standards list — bumps this, and a slower answer to the old question is
 * dropped instead of landing under the new one.
 */
let prefillSeq = 0;

/**
 * The combined guide column ("Qur’an & Islamic Studies") the last prefill was
 * taken from, or '' when it came from the subject's own row. Set only for a
 * separated subject asked for a week the school's separated plan does not have.
 */
const prefillCombined = ref('');

/** Drop a prefill in flight and the "Filling…" and "Kept" states with it. */
function cancelPrefill() {
    prefillSeq++;
    prefilling.value = false;
    prefillKept.value = false;
    prefillCombined.value = '';
}

/**
 * Copy the week's cell into the form. Every field lands EDITABLE and nothing is
 * saved until the teacher presses Save — prefill is a draft, not a write.
 * Fields the teacher has already written are left alone. `auto` is a week pick
 * rather than the button: a week the guide has nothing for is then not an error.
 */
const prefillFromGuide = async (auto = false) => {
    const seq = ++prefillSeq;
    // Which plan this fill is for: the teacher may open another subject's plan
    // on the same day before the guide answers.
    const ticket = planForms.current();
    const asked = {
        day: planDate.value,
        grade: planForm.value.grade_label,
        subject: planForm.value.subject,
        week: planForm.value.curriculum_week_no,
    };
    prefilling.value = true;
    prefillKept.value = false;
    prefillCombined.value = '';
    planError.value = '';
    try {
        const q = new URLSearchParams({
            grade: asked.grade,
            subject: asked.subject,
            week: String(asked.week),
            // Names the class, so the guide answers only with the subjects THIS
            // teacher teaches here (the same fence the subject list and the save use).
            group_id: String(groupId.value),
        });
        const res = await TeacherApiService.get(
            `/api/teacher/masjids/${masjidId.value}/curriculum?${q}`
        );
        // The teacher opened another plan while the guide answered: this cell
        // is for the plan she left, not the one on screen now.
        if (!planForms.isCurrent(ticket)) return;
        const f = planForm.value;
        if (seq !== prefillSeq || planDate.value !== asked.day || f.grade_label !== asked.grade
            || f.subject !== asked.subject || f.curriculum_week_no !== asked.week) return;

        const cell = res.data?.data?.cell;
        if (!cell) {
            if (!auto) planError.value = 'The guide has nothing for that week.';
            return;
        }

        // A separated subject asked for a week past the school's separated plan
        // (weeks 9 on) is answered with the combined guide line; `prefillCombined`
        // (set below, when something is written) says so, so it is never mistaken
        // for a week of its own.
        // Every call runs: `||` after the call, never before it.
        let wrote = autoFill('standard_code', cell.standard_code);
        wrote = autoFill('objective', cell.objective) || wrote;
        wrote = autoFill('assessment_formative', cell.assessment_formative) || wrote;
        wrote = autoFillOutcome(cell.learning_outcome) || wrote;

        // Cross-subject integration, written from the same week's sibling cells
        // so a teacher is not asked to remember what Science is doing.
        const siblings = (cell.siblings ?? []) as { subject: string; focus: string; objective?: string | null }[];
        const { islamic, others } = islamicIntegration(siblings);
        wrote = autoFill('cross_integration_islamic', islamic) || wrote;
        wrote = autoFill('cross_integration_subject', others) || wrote;

        // A saved plan's fields are the teacher's, so a week pick on it can
        // change nothing — then say so, rather than badge it "from the guide".
        if (wrote) {
            prefillCombined.value = cell.from_combined_guide ? String(cell.guide_subject ?? '') : '';
            planForm.value.prefill_source = cell.prefill_source ?? 'pacing guide';
            openFilledSections();
        } else {
            prefillKept.value = true;
        }
    } catch {
        if (seq === prefillSeq && planForms.isCurrent(ticket)) planError.value = 'Could not read the pacing guide.';
    } finally {
        if (seq === prefillSeq) prefilling.value = false;
    }
};

// ---------- type-to-find a standard ----------
// Al-Razi's teachers retyped standards the guide already held. Typing into the
// Standard code box searches the guide by code or topic; a pick fills the plan.
const stdMatches = ref<any[]>([]);
/** The field whose list is showing, or '' when none is. */
const stdOpen = ref('');
/** The highlighted suggestion; -1 is none, so Enter does nothing until an arrow key chooses. */
const stdActive = ref(-1);
/** The query that came back empty, so "nothing matches" never shows for a stale one. */
const stdEmptyFor = ref<string | null>(null);
/** What is in the box now, read from the box: see onStandardInput. */
const stdTyped = ref('');
/**
 * Search text typed into a box and left there without a pick — "fractions" in
 * the Code box. It is a question, not a code, so a later fill may replace it.
 * Text that looks like a code ("K.CC.1", "MP1") is the teacher's own and stays.
 */
const stdLeft = ref<Record<string, string>>({});
const looksLikeCode = (s: string) => /\d/.test(s) && !/\s/.test(s);
/** An on-screen keyboard is composing a word in the box (compositionstart → end). */
const stdComposing = ref(false);
let stdTimer: ReturnType<typeof setTimeout> | undefined;
let stdSeq = 0;

const closeStandards = () => {
    clearTimeout(stdTimer);
    stdSeq++;
    stdOpen.value = '';
    stdActive.value = -1;
    stdMatches.value = [];
};

/**
 * The query is read from the input, not from planForm: while an Android keyboard
 * is composing a word, v-model does not write the model until the word ends, so
 * the model lags a keystroke behind for the whole word. `typed` is false for a
 * focus, which searches what is there without calling it search text.
 */
const onStandardInput = (field: string, e: Event, typed = true) => {
    const q = String((e.target as HTMLInputElement | null)?.value ?? '').trim();
    stdTyped.value = q;
    // New text is the teacher's until a finished search shows it is a topic
    // the guide knows (searchStandards) — so leaving the box before the answer
    // lands keeps it, as the note under the box says.
    if (typed) delete stdLeft.value[field];
    clearTimeout(stdTimer);
    stdOpen.value = field;
    stdActive.value = -1;
    if (q.replace(/[^\p{L}\p{N}]/gu, '').length < 2) {
        stdSeq++;
        stdMatches.value = [];
        stdEmptyFor.value = null;
        return;
    }
    stdTimer = setTimeout(() => searchStandards(field, q, typed), 200);
};

/** `typed` is false for the search a focus runs over text already in the box. */
const searchStandards = async (field: string, q: string, typed = true) => {
    const seq = ++stdSeq;
    try {
        const params = new URLSearchParams({ q });
        if (planForm.value.grade_label) params.set('grade', planForm.value.grade_label);
        if (planForm.value.subject) params.set('subject', planForm.value.subject);
        if (planForm.value.curriculum_week_no) params.set('week', String(planForm.value.curriculum_week_no));
        // The class, so a teacher limited to some subjects searches only those.
        params.set('group_id', String(groupId.value));
        const res = await TeacherApiService.get(
            `/api/teacher/masjids/${masjidId.value}/curriculum/standards?${params}`
        );
        // A slower answer to an older query must not replace a newer one.
        if (seq !== stdSeq || stdOpen.value !== field) return;
        stdMatches.value = res.data?.data?.matches ?? [];
        // A new list, so no row of the old one is highlighted in it.
        stdActive.value = -1;
        stdEmptyFor.value = stdMatches.value.length ? null : q;
        // Topic words the guide answered ("fractions"), left in the box without
        // a pick, are a question a later fill may answer. A hand-typed code, or
        // text the guide knows nothing of, stays the teacher's.
        // Only text typed now: a focus on a saved plan's own label must not
        // hand it to the next fill. Compared with the box itself, which an
        // Android keyboard updates before v-model does.
        if (typed && stdMatches.value.length && !looksLikeCode(q) && stdTyped.value === q) {
            stdLeft.value[field] = q;
        }
    } catch {
        if (seq === stdSeq) stdMatches.value = [];
    }
};

const onStandardKey = (e: KeyboardEvent) => {
    // Keys pressed while a keyboard is composing belong to the composition.
    if (e.isComposing) return;
    const n = stdMatches.value.length;
    if (e.key === 'Escape') { closeStandards(); return; }
    if (!n) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        stdActive.value = (stdActive.value + 1) % n;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        stdActive.value = stdActive.value <= 0 ? n - 1 : stdActive.value - 1;
    } else if (e.key === 'Enter' && stdActive.value >= 0 && stdActive.value < n) {
        e.preventDefault();
        pickStandard(stdMatches.value[stdActive.value]);
    }
};

const weeksLabel = (weeks: number[]) => {
    if (weeks.length === 1) return `Week ${weeks[0]}`;
    const shown = weeks.slice(0, 4).join(', ');
    return weeks.length > 4 ? `Weeks ${shown} +${weeks.length - 4}` : `Weeks ${shown}`;
};

/**
 * Fill the plan from one suggestion. The grade, subject and week follow the
 * pick only where the form has none yet — a teacher who chose Grade 3 and cites
 * a Grade 4 standard keeps their Grade 3.
 */
const pickStandard = async (m: any) => {
    if (!m) return;
    const typedIn = stdOpen.value;
    // A tap on a row keeps focus in the box (mousedown is prevented), so an
    // Android keyboard may still be composing the word. Blurring commits it
    // NOW; otherwise the composed text lands later, through v-model, over
    // the pick. The commit's own input event runs before the writes below.
    // Only then: a keyboard pick keeps focus in the box, as a combobox should.
    const box = document.getElementById(`std-${typedIn}`);
    if (stdComposing.value && box && document.activeElement === box) (box as HTMLElement).blur();
    stdComposing.value = false;
    closeStandards();
    // The pick is the answer now; a week prefill still in flight is not.
    cancelPrefill();

    // An uncoded row (the Islamic Studies column) clears the search text out
    // of the code box rather than leaving "wudu" standing as a standard.
    autoFill('standard_code', m.standard_code, typedIn);
    // The school's own Objective where the row has one; the Focus Skill otherwise,
    // as a week prefill does (CurriculumWeek::toPrefillArray).
    autoFill('objective', m.objective ?? m.focus);
    autoFillOutcome(m.learning_outcome);
    autoFill('assessment_formative', m.assessment_formative);

    const f = planForm.value;
    let reload = false;
    if (!f.grade_label) { f.grade_label = m.grade_label; reload = true; }
    if (f.grade_label === m.grade_label) {
        if (!f.subject) { f.subject = m.subject; subjectOther.value = false; reload = true; }
        if (f.subject === m.subject && !f.curriculum_week_no) f.curriculum_week_no = m.week_no;
    }
    f.prefill_source = m.prefill_source || 'pacing guide';
    openFilledSections();

    if (reload) await loadCurriculum(f.grade_label, f.subject);
};

/**
 * The guide is weekly and a week is four or five lessons, so this is the honest
 * answer: the teacher decides, the tool does the typing. Five client-side PUTs
 * of the SAVED plan — no new endpoint, and nothing is copied that is not already
 * stored, so a half-typed form cannot be broadcast across the week.
 */
const copyAcrossWeek = async () => {
    const source = selectedPlan.value;
    if (!source) return;

    // Read once: the teacher may change day, plan or week while the copy runs,
    // and none of that may change which days it writes or which plans it finds.
    const sourceDay = planDate.value;
    const list = plans.value;
    const days = weekdaysOnly.value.map((d) => d.iso);
    const written = new Set<number>();

    copying.value = true;
    planError.value = '';
    let failed = false;
    try {
        for (const iso of days) {
            if (iso === sourceDay) continue;
            // THIS subject's plan on each other day (lessonPlans.copyRequest):
            // Thursday's Math plan is rewritten by its id, its Science plan is
            // not touched, and a day with no Math plan gets one.
            const req = copyRequest(base.value, list, source, iso);
            const res = await (req.method === 'put'
                ? TeacherApiService.put(req.url, req.payload)
                : TeacherApiService.post(req.url, req.payload));
            const id = res?.data?.data?.id ?? subjectClash(list, iso, source.subject, null)?.id;
            if (id) written.add(id);
        }
    } catch {
        failed = true;
    }
    try {
        // Reload even after a failure: the days already written must show, or a
        // retry reads the old list and POSTs plans that now exist. The form
        // reloads only if it shows a plan this copy wrote AND the teacher has
        // not started changing it; anything else keeps what she is writing.
        await loadLessonPlans(() => planId.value !== null && written.has(planId.value) && !planDirty());
    } finally {
        // After the reload, which clears the message when it starts.
        if (failed) planError.value = 'Could not copy across the week.';
        copying.value = false;
    }
};

/**
 * Reload the week, and say whether the form was re-synced from it.
 *
 * `resync` false keeps the form as the teacher left it: a save, copy or removal
 * answering after she opened another plan must not reload that plan's saved
 * copy over what she is writing. It may be a question, asked when the week's
 * answer LANDS — she can switch plans during this request too. The plan she is
 * on is kept only while it still exists; one that has gone falls back as a
 * resync would.
 */
const loadLessonPlans = async (resync: boolean | (() => boolean) = true): Promise<boolean> => {
    const seq = ++plansSeq;
    if (resync === true) resyncOwed = true;
    planError.value = '';
    try {
        const days = weekDays.value;
        const res = await TeacherApiService.get(
            `${base.value}/lesson-plans?from=${days[0].iso}&to=${days[6].iso}`
        );
        if (seq !== plansSeq) return false;
        lessonsLoaded = true;
        plans.value = res.data?.data?.plans ?? [];
        planHidden.value = new Set(res.data?.data?.hidden_fields ?? []);
        planWeekdays.value = Array.isArray(res.data?.data?.meeting_weekdays) ? res.data.data.meeting_weekdays : null;
        // An owed re-sync opens the week's plan in an UNTOUCHED form only: a
        // draft the teacher started since (a new plan on the blank form) stays.
        const again = (resyncOwed && !planDirty()) || (typeof resync === 'function' ? resync() : resync);
        resyncOwed = false;
        if (!again && (planId.value === null || plans.value.some((p) => p.id === planId.value))) return false;
        // Stay on the plan that was open if it is still there (a save, a copy),
        // else the day's first plan, else a new one.
        planId.value = pickPlan(plans.value, planDate.value, planId.value);
        syncPlanForm();
        return true;
    } catch {
        if (seq === plansSeq) {
            planError.value = 'Could not load this week.';
            // Nothing replaced this load, so nothing is owed by it.
            resyncOwed = false;
        }
        return false;
    }
};

/** The form always shows the SELECTED plan of the selected day — never a stale one. */
const syncPlanForm = () => {
    const p = selectedPlan.value;
    const blank = emptyPlan();

    // Arrays are copied: shared with the loaded list, an unsaved tick or outcome
    // wrote straight into it — a Copy then sent it, and the snapshot below saw
    // the draft as the saved plan.
    planForm.value = p
        ? { ...blank, ...Object.fromEntries(Object.entries(p).map(([k, v]) =>
            [k, Array.isArray(v) ? [...v] : (v ?? blank[k as keyof typeof blank])])) }
        : blank;

    // Arrays must never come back null, or v-model has nothing to bind.
    planForm.value.learning_outcomes = planForm.value.learning_outcomes ?? [];
    planForm.value.teaching_methods = planForm.value.teaching_methods ?? [];
    planForm.value.attachments = planForm.value.attachments ?? [];
    planSnapshot = JSON.stringify(planForm.value);

    // A section that already has content opens itself: seven closed rows on a
    // written plan reads as an empty plan. Reflection also opens once the day
    // has happened, because it is written after the lesson, not at planning time.
    const opened: Record<string, boolean> = {};
    for (const sec of planSections) opened[sec.key] = sectionFilled(sec);
    if (planDate.value <= todayIso) opened.reflection = true;
    planOpen.value = opened;
    autoFilled.value = {};
    stdLeft.value = {};
    cancelPrefill();
    planForms.replace();
    closeStandards();

    // The pickers follow the plan on screen. Another subject's plan brings
    // another subject's weeks, and a week list left over from the last plan
    // would label this one's week with that subject's focus. The lists are
    // emptied first so the "subject not in the guide" check never compares
    // this plan's subject with the last plan's list.
    subjectOther.value = false;
    curriculum.value = { grades: curriculum.value.grades, subjects: [], weeks: [] };
    const grade = planForm.value.grade_label || undefined;
    loadCurriculum(grade, grade ? (planForm.value.subject || undefined) : undefined);

    planSaved.value = false;
};

watch(planDate, (iso) => selectPlan(pickPlan(plans.value, iso, planId.value)));
watch(weekStart, () => loadLessonPlans());

const shiftWeek = (delta: number) => {
    const d = new Date(weekStart.value + 'T00:00:00');
    d.setDate(d.getDate() + delta * 7);
    // The open day moves with the week. Left behind, it was a day the loaded
    // week does not hold: no plan chips, and the one-plan-per-subject check
    // read an empty day while the server refused the clash anyway.
    const day = new Date(planDate.value + 'T00:00:00');
    day.setDate(day.getDate() + delta * 7);
    planId.value = null;
    planDate.value = localDay(day);
    weekStart.value = localDay(d);
};

const savePlan = async () => {
    planSaving.value = true;
    planError.value = '';
    planSaved.value = false;
    try {
        // The WHOLE object, every time. The API declares every template field
        // nullable rather than sometimes, so an omitted field CLEARS — which is
        // why there is deliberately no per-section autosave here.
        const payload = {
            ...planForm.value,
            session_date: planDate.value,
            title: planForm.value.title || null,
            subject: planForm.value.subject || null,
            curriculum_week_no: planForm.value.curriculum_week_no || null,
            learning_outcomes: planForm.value.learning_outcomes.filter((o: string) => o && o.trim()),
            // The files under Activities, as the ids of the class's own Files, in
            // the order listed. Always sent from this form: the server treats an
            // ABSENT key as "leave the plan's files alone" (an old screen), and
            // this screen shows the list, so what it shows is what it saves.
            resource_ids: attachmentIds(planForm.value),
            attachments: undefined,
        };
        // An open plan is rewritten by its id; a new one is created, and the
        // server refuses it if the day already has that subject.
        const ticket = planForms.current();
        const req = planSaveRequest(base.value, planId.value);
        const res = req.method === 'post'
            ? await TeacherApiService.post(req.url, payload)
            : await TeacherApiService.put(req.url, payload);
        // Stay on the saved plan only if it is still the one on screen: a chip
        // clicked while the save was in flight has opened another, and the
        // teacher stays there.
        const savedId = res.data?.data?.id ?? planId.value;
        if (planForms.isCurrent(ticket)) planId.value = savedId;
        // Re-sync only a form showing the plan that was saved — asked when the
        // week's answer lands, since the teacher can switch plans during that
        // request too. Another plan opened meanwhile keeps what she is writing.
        const resynced = await loadLessonPlans(
            () => planForms.isCurrent(ticket) || planId.value === savedId);
        // "Saved" belongs beside the plan that was saved, and nowhere else.
        planSaved.value = resynced && planId.value === savedId;
    } catch (e: any) {
        planError.value = e?.response?.data?.data?.subject?.[0]
            ?? e?.response?.data?.data?.session_date?.[0]
            ?? e?.response?.data?.data?.body?.[0]
            ?? e?.response?.data?.data?.resource_ids?.[0]
            ?? 'That plan could not be saved.';
    } finally {
        planSaving.value = false;
    }
};

/** Remove the open plan; the day's other subjects' plans stay. */
const deletePlan = async () => {
    if (planId.value === null || planDeleting.value) return;
    const ticket = planForms.current();
    planDeleting.value = true;
    try {
        try {
            await TeacherApiService.delete(planDeleteUrl(base.value, planId.value));
        } catch (e) {
            // A 404 is "nothing to delete", not a failure: carry on as after a removal that worked.
            if (!planAlreadyGone(e)) throw e;
        }
        // Still on the removed plan: the day's next one opens. Moved on to
        // another while the removal ran: she stays there, with her draft.
        if (planForms.isCurrent(ticket)) {
            planId.value = null;
            // Asked when the week's answer lands: a plan opened during that
            // request keeps its draft too.
            await loadLessonPlans(() => planForms.isCurrent(ticket));
        } else {
            await loadLessonPlans(false);
        }
    } catch {
        planError.value = 'That plan could not be removed.';
    } finally {
        planDeleting.value = false;
    }
};

// ---------- gradebook ----------
const assignments = ref<any[]>([]);
const blankAssignment = () => blankWorkForm({ scale: defaultScale.value, today: todayIso, subject: defaultSubject.value });
const assignmentForm = ref<any>(blankWorkForm({ scale: 'levels', today: todayIso }));
const creatingAssignment = ref(false);
/** The piece of work being edited, or null while the form is adding new work. */
const editingId = ref<number | null>(null);

// What the school and the class say about work: the types, the class's weights,
// the subjects THIS teacher may file work under (already limited by the server's
// subject fence) and whether the school teaches from a pacing guide. All read
// from the payload, none decided here.
const workTypes = ref<{ key: string; label: string }[]>([]);
const classWeights = ref<Record<string, number>>({});
const weightingEnabled = ref(false);
const weightMax = ref(100);
const gradeSubjects = ref<{ name: string; key: string }[]>([]);
const defaultSubject = ref<string | null>(null);
const standardsEnabled = ref(false);

const gradesView = ref<'work' | 'students'>('work');
const showWeights = ref(false);
// Only a teacher of every subject in the class (or the office) changes its weights; a limited
// teacher sees them read-only. The server refuses the change either way.
const canChangeWeights = computed(() => mayChangeWeights(group.value?.my_subjects));
const weightsForm = ref<Record<string, string>>({});
const savingWeights = ref(false);
const weightsSaved = ref(false);
const confirmClearWeights = ref(false);

const gradeContext = computed(() => ({
    subjects: gradeSubjects.value,
    weightingEnabled: weightingEnabled.value,
    standardsEnabled: standardsEnabled.value,
}));
const workReady = computed(() => workFormReady(assignmentForm.value, gradeContext.value));
const untypedNoteText = untypedNote;
/**
 * Work in the list that a weighted class would leave out of its average for want of a type: no type and no
 * weight of its own. Simple-scale work is never averaged whatever it is given, so a type would not help it
 * and it is not counted here (the server leaves it out of `untyped_excluded` the same way).
 */
const untypedInList = computed(() => untypedInWork(assignments.value));
/** What a blank weight box inherits, said in the box so leaving it blank is a decision a teacher can read. */
const inheritedWeightText = computed(() => {
    if (assignmentForm.value.scale === SIMPLE_SCALE) return NOT_AVERAGED.replace(/^./, (c) => c.toUpperCase());
    const w = effectiveWeight({ type: assignmentForm.value.type || null }, classWeights.value, weightingEnabled.value);
    return w === null ? 'Type sets it' : `Counts ${w}`;
});
/**
 * The one grade this class teaches, or null for a combined class. Passed to the
 * standards search to rank that grade's rows first; with two grades in the room
 * there is no one grade to prefer, and the search then ranks by subject alone.
 */
const singleGrade = computed<string | null>(() => {
    const grades = new Set(students.value.map((s) => s.grade_label).filter((g): g is string => !!g));
    return grades.size === 1 ? [...grades][0] : null;
});

// THE KEY, and the school's default scale, both read from the server rather
// than hardcoded here. What a 3 means is a fact about the school, not about
// this component, and a copy of it in the SPA is a copy that goes stale.
const levelKey = ref<any[]>([]);
const defaultScale = ref('levels');
// The scales THIS school offers (App\Support\SchoolSettings): levels or points,
// or points and Excellent / Good / Needs work where a SuperAdmin switched on
// `simple_marking`. The words come from the payload too.
const gradingScales = ref<string[]>(['levels', 'points']);
const SCALE_LABELS: Record<string, string> = { levels: 'Levels 4–1', points: 'Points', simple: 'Excellent / Good / Needs work' };
const simpleMarks = ref<{ value: number; label: string }[]>([]);
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
        levelKey.value = res.data?.performance_levels ?? levelKey.value;
        defaultScale.value = res.data?.default_scale ?? defaultScale.value;
        gradingScales.value = res.data?.scales ?? gradingScales.value;
        simpleMarks.value = res.data?.simple_marks ?? simpleMarks.value;
        workTypes.value = res.data?.types ?? workTypes.value;
        classWeights.value = { ...(res.data?.weights ?? {}) };
        weightingEnabled.value = !!res.data?.weighting_enabled;
        weightMax.value = res.data?.weight_max ?? weightMax.value;
        gradeSubjects.value = res.data?.subjects ?? [];
        defaultSubject.value = res.data?.default_subject ?? null;
        standardsEnabled.value = !!res.data?.standards_enabled;
        // A form nobody has started takes the school's defaults; one in progress is left alone.
        if (editingId.value === null && !assignmentForm.value.title) {
            assignmentForm.value.scale = defaultScale.value;
            if (!assignmentForm.value.subject && defaultSubject.value) assignmentForm.value.subject = defaultSubject.value;
        }
    } catch {
        gradesError.value = 'Could not load the gradebook.';
    }
};

/** Add new work, or save changes to the work being edited: one form, one request. */
const saveWork = async () => {
    if (!workReady.value) return;
    creatingAssignment.value = true;
    gradesError.value = '';
    try {
        const body = workRequest(assignmentForm.value, gradeContext.value);
        if (editingId.value !== null) {
            await TeacherApiService.put(`${base.value}/assignments/${editingId.value}`, body);
        } else {
            await TeacherApiService.post(`${base.value}/assignments`, body);
        }
        editingId.value = null;
        assignmentForm.value = blankAssignment();
        await loadAssignments();
    } catch (e: any) {
        gradesError.value = firstFieldError(e, editingId.value !== null ? 'That work could not be saved.' : 'That work could not be added.');
    } finally {
        creatingAssignment.value = false;
    }
};

const startEdit = (a: any) => {
    gradesError.value = '';
    editingId.value = a.id;
    assignmentForm.value = workFormFrom(a);
    gradesView.value = 'work';
    openAssignment.value = null;
    nextTick(() => document.getElementById('work-subject')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
};

const cancelEdit = () => {
    editingId.value = null;
    assignmentForm.value = blankAssignment();
    gradesError.value = '';
};

// ---------- the class's weights ----------
const toggleWeights = () => {
    showWeights.value = !showWeights.value;
    confirmClearWeights.value = false;
    weightsSaved.value = false;
    gradesError.value = '';
    if (showWeights.value) weightsForm.value = weightsFormFrom(classWeights.value, workTypes.value);
};

const applyWeights = (data: any) => {
    classWeights.value = { ...(data?.weights ?? {}) };
    weightingEnabled.value = !!data?.weighting_enabled;
    weightsForm.value = weightsFormFrom(classWeights.value, workTypes.value);
};

const saveWeights = async () => {
    gradesError.value = '';
    weightsSaved.value = false;
    const request = weightsRequest(weightsForm.value, workTypes.value, weightMax.value);
    if (!request.ok) { gradesError.value = request.message; return; }

    savingWeights.value = true;
    try {
        const res = await TeacherApiService.put(`${base.value}/grade-weights`, { weights: request.weights });
        applyWeights(res.data?.data);
        weightsSaved.value = true;
        await loadAssignments();
        studentGrades.value = null; openStudentId.value = null;
    } catch (e: any) {
        gradesError.value = firstFieldError(e, 'The weights could not be saved.');
    } finally {
        savingWeights.value = false;
    }
};

const clearWeights = async () => {
    gradesError.value = '';
    savingWeights.value = true;
    try {
        const res = await TeacherApiService.put(`${base.value}/grade-weights`, { clear: true });
        applyWeights(res.data?.data);
        confirmClearWeights.value = false;
        weightsSaved.value = true;
        // Clearing removes every per-work weight too; a form holding one would send it back.
        if (assignmentForm.value.weight !== '') assignmentForm.value.weight = '';
        await loadAssignments();
        studentGrades.value = null; openStudentId.value = null;
    } catch (e: any) {
        gradesError.value = firstFieldError(e, 'The weights could not be cleared.');
    } finally {
        savingWeights.value = false;
    }
};

// ---------- the Students view ----------
const openStudentId = ref<number | null>(null);
const studentGrades = ref<any>(null);
const studentLoading = ref(false);
const studentError = ref('');
const studentLines = computed(() => averageLines(studentGrades.value?.summary, !!studentGrades.value?.fenced));
/** Said under the figures when this teacher teaches only some subjects of the class. */
const studentFencedNote = computed(() => fencedNote(studentGrades.value?.fenced));

const showStudentsView = () => {
    gradesView.value = 'students';
    gradesError.value = '';
    if (!levelKey.value.length) loadAssignments();
};

const toggleStudent = async (s: any) => {
    if (openStudentId.value === s.membership_id) { openStudentId.value = null; return; }
    openStudentId.value = s.membership_id;
    studentGrades.value = null;
    studentError.value = '';
    studentLoading.value = true;
    try {
        const res = await TeacherApiService.get(`${base.value}/members/${s.membership_id}/grades`);
        // A slower answer for a child the teacher has already moved off is dropped.
        if (openStudentId.value !== s.membership_id) return;
        studentGrades.value = res.data?.data ?? null;
        levelKey.value = res.data?.performance_levels ?? levelKey.value;
    } catch {
        if (openStudentId.value === s.membership_id) studentError.value = 'Could not load that child’s marks.';
    } finally {
        if (openStudentId.value === s.membership_id) studentLoading.value = false;
    }
};

/**
 * What one mark SAYS, from the payload's own words: "Not handed in" and "Excused"
 * for those statuses (never a zero), the school's word for a level or simple
 * mark, and "8 / 10" for points. A levels mark is never drawn as a percentage.
 */
const scoreText = (sc: any): string => {
    if (sc.status === 'missing') return 'Not handed in';
    if (sc.status === 'excused') return 'Excused';
    if (sc.points_earned === null || sc.points_earned === undefined) return '—';
    const a = sc.assignment;
    if (a?.scale === 'levels') {
        const l = levelKey.value.find((k: any) => k.level === Number(sc.points_earned));
        return l ? `${l.level} · ${l.short_label}` : String(sc.points_earned);
    }
    if (a?.scale === 'simple') return sc.mark_label ?? String(sc.points_earned);
    return `${sc.points_earned} / ${a?.points_possible ?? '?'}`;
};

/**
 * Choose a performance level for one child.
 *
 * Tapping the level a child already has CLEARS it back to unmarked, the way
 * the Missing/Excused buttons toggle. Without that a mis-tap would be
 * uncorrectable on the levels scale — there is no empty box to blank out.
 */
const setLevel = (membershipId: number, level: number) => {
    const cell = marks_g.value[membershipId];
    if (!cell) return;
    const already = cell.status === 'scored' && cell.points_earned === level;
    cell.status = already ? null : 'scored';
    cell.points_earned = already ? null : level;
    scoresSaved.value = false;
};

const openScores = async (a: any) => {
    gradesError.value = '';
    scoresSaved.value = false;
    try {
        const res = await TeacherApiService.get(`${base.value}/assignments/${a.id}`);
        openAssignment.value = res.data?.data ?? null;
        levelKey.value = res.data?.performance_levels ?? levelKey.value;
        simpleMarks.value = res.data?.simple_marks ?? simpleMarks.value;
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
const composePhotos = ref<File[]>([]);

const startCompose = () => {
    // Defaults to the FIRST student rather than the whole class: the common case
    // is one family, and a mis-sent class-wide thread cannot be recalled.
    composeForm.value = {
        about_membership_id: students.value[0]?.membership_id ?? null,
        subject: '', body: '',
    };
    composePhotos.value = [];
    composeError.value = '';
    messageLater.reset();
    composing.value = true;
};

const createThread = async () => {
    sendingCompose.value = true;
    composeError.value = '';
    try {
        const about = composeForm.value.about_membership_id;
        const fields: Record<string, string | number> = {
            subject: composeForm.value.subject,
            // `participant` reaches one child's guardians; `group` reaches every
            // family in the class — the same audience as the class story.
            scope: about ? 'participant' : 'group',
            ...(about ? { about_membership_id: about } : {}),
            body: composeForm.value.body,
        };

        // "Send later" (T-002.4): the words wait as a schedule row and open at their
        // time, in the school's clock. Text only, and its OWN endpoint: a time sent to
        // `/threads` is refused, never quietly sent now.
        if (messageLater.enabled.value) {
            await TeacherApiService.post(`${base.value}/scheduled-messages`, { ...fields, ...messageLater.fields() });
            composing.value = false;
            messageLater.reset();
            await loadScheduledMessages();
            return;
        }

        if (composePhotos.value.length) {
            await TeacherApiService.postForm(`${base.value}/threads`, withPhotos(fields, composePhotos.value));
        } else {
            await TeacherApiService.post(`${base.value}/threads`, fields);
        }

        composing.value = false;
        composePhotos.value = [];
        await loadThreads();
    } catch (e: any) {
        composeError.value = photoErrorText(e, 'That message could not be sent.', 'message');
    } finally {
        sendingCompose.value = false;
    }
};

// ---------- class files ----------
const resources = ref<any[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const fileForm = ref<{ title: string; visibility: string; file: File | null; recipientIds: number[] }>({
    title: '', visibility: 'staff', file: null, recipientIds: [],
});
const uploading = ref(false);
const filesError = ref('');

const studentName = (s: any): string =>
    `${s?.contact?.first_name ?? ''} ${s?.contact?.last_name ?? ''}`.trim() || 'Unnamed student';

/**
 * What a row's audience is, in words.
 *
 * `recipient_count` is served for EVERY visibility (0 on a whole-class or
 * staff-only file), so this never has to special-case an absent key — the shape
 * shown must not depend on the value being shown.
 */
const audienceLabel = (r: any): string => {
    if (r?.visibility === 'families') return 'Whole class';
    if (r?.visibility !== 'students') return 'Only you';

    const n = Number(r?.recipient_count ?? 0);

    // Zero is reachable and is not "everyone": every student the file named has
    // come off the roster, so it reaches staff and nobody else.
    if (n === 0) return 'No students left';

    return `${n} ${n === 1 ? 'student' : 'students'}`;
};

const audienceBadgeClass = (r: any): string => (r?.visibility === 'families'
    ? 'bg-warning-subtle text-warning-emphasis'
    : r?.visibility === 'students'
        ? 'bg-info-subtle text-info-emphasis'
        : 'bg-light text-muted');

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

        // Only when the audience is the one that has recipients: the server
        // REFUSES names on any other visibility, so sending an empty array on a
        // whole-class file would be a 422 rather than a harmless no-op.
        if (fileForm.value.visibility === 'students') {
            for (const id of fileForm.value.recipientIds) {
                form.append('recipient_membership_ids[]', String(id));
            }
        }

        // postForm, never put/post: this is the one write that must NOT declare
        // application/json — the browser has to write the multipart boundary.
        await TeacherApiService.postForm(`${base.value}/resources`, form);

        fileForm.value = { title: '', visibility: 'staff', file: null, recipientIds: [] };
        if (fileInput.value) fileInput.value.value = '';
        await loadResources();
    } catch (e: any) {
        // The recipient refusal is read too. Without it, a teacher who ticked a
        // child the roster no longer holds would be told "that file could not be
        // uploaded" and never learn which half of the form the server objected
        // to — a 422 whose reason the screen throws away.
        filesError.value = e?.response?.data?.data?.file?.[0]
            ?? e?.response?.data?.data?.title?.[0]
            ?? e?.response?.data?.data?.recipient_membership_ids?.[0]
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

// ---------- the unread-messages number ----------
// Messages from other people this teacher has not seen, counted by the server
// (`unread_messages` on the class, `meta.unread_total` on the thread list).
const unreadMessages = computed(() => unreadNumber(group.value?.unread_messages));

let lastUnreadRefresh: number | null = null;

// Coming back to this window: a parent may have written while it was behind another
// one. The whole class is never reloaded (that would blank the screen and lose a
// half-written reply), and not more than once in a few seconds.
//
// Once the conversations have been listed, the LIST is what is refreshed, quietly:
// it carries the class number too, and without it the tab would say "1 new" while no
// row says which conversation. A quiet list load touches no draft, no chosen photo
// and not the open conversation. Before the list exists, only the number is asked for.
const refreshUnread = async () => {
    if (document.visibilityState === 'hidden' || !group.value) return;
    if (!focusRefreshDue(lastUnreadRefresh, Date.now(), FOCUS_REFRESH_GAP_MS)) return;
    lastUnreadRefresh = Date.now();

    if (threadsLoaded.value) {
        await loadThreads(true);
        return;
    }

    try {
        const res = await TeacherApiService.get(base.value);
        if (group.value && res.data?.data) group.value.unread_messages = res.data.data.unread_messages ?? 0;
    } catch {
        // The number is a convenience: a failed refresh leaves the last one standing.
    }
};

onMounted(() => {
    window.addEventListener('focus', refreshUnread);
    document.addEventListener('visibilitychange', refreshUnread);
});
onBeforeUnmount(() => {
    window.removeEventListener('focus', refreshUnread);
    document.removeEventListener('visibilitychange', refreshUnread);
});

// ============================================================ LETTERS
const selected = ref<any>(null);
const tracker = ref<any>(null);
const trackerLoading = ref(false);
// The open tile's KEY (a drill id for English, a letter id for Arabic); the card's letter is derived from it.
const openTile = ref<string | null>(null);
const marking = ref<string | null>(null);
const letterError = ref('');
const savingStage = ref(false);
const stageNote = ref('');

// The runs of tiles to draw: two for English (Capitals, Lower case), one for Arabic.
const letterRunsOf = computed(() => letterRuns(tracker.value));

const letter = computed(() => {
    const id = letterIdOfTile(letterRunsOf.value, openTile.value);

    return id === null ? null : tracker.value?.letters?.find((l: any) => String(l.id) === id) ?? null;
});

/**
 * The tracks this tab can show, and which one it is showing.
 *
 * NOT remembered across page loads: a teacher opening a class expects the
 * qāʿidah, which is also what the endpoints answer when no `?alphabet=` is
 * sent, so the screen and the API agree about what "no choice made" means. The
 * ids are the server's allowlist (`CurriculumRegistry::ALPHABETS`) — anything
 * else is a 422 rather than a quiet fallback to Arabic.
 */
const ALPHABETS = [
    { id: 'arabic', label: 'Arabic' },
    { id: 'english', label: 'English' },
] as const;
const lettersAlphabet = ref<string>('arabic');

/** Which way this alphabet is laid out, as the payload declares it — never a constant. */
const lettersDir = computed(() => tracker.value?.direction ?? (lettersAlphabet.value === 'english' ? 'ltr' : 'rtl'));

/** What to call the track before any tracker has been loaded to name its stage. */
const alphabetHeading = computed(() => (lettersAlphabet.value === 'english' ? 'English letters' : 'Arabic stage'));

/**
 * The open letter's heading.
 *
 * `arabic_name — transliteration` was safe while there was one alphabet. An
 * English letter carries neither, so the same template printed a leading em
 * dash in front of a blank; the dash now appears only when there are two things
 * to separate.
 */
const letterHeading = computed(() => {
    const l = letter.value;
    if (!l) return '';

    return [l.arabic_name, l.transliteration ?? l.glyph].filter(Boolean).join(' — ');
});

/**
 * The CLASS overview — the same `GET .../letters` the office's screen reads.
 *
 * This tab fetched nothing until a child was opened, so a teacher got a bare
 * list of names: no stage summary, no per-child progress, and — because the
 * LADDER arrives on that payload — no way to see which part of the qāʿidah the
 * class is on, or to move it, unless the group object happened to carry
 * `arabic_stages`. The office's copy of the same tab had all three. Reading the
 * overview when the tab opens is what closes that gap; the endpoint was already
 * mounted in this realm (routes/teacher.php) and fenced by `teacher.leads`, so
 * this adds a read a teacher was always entitled to and no new authority.
 */
const lettersOverview = ref<any>(null);

/**
 * Who to list, and what is known about them.
 *
 * The overview leads because it carries the counts. The roster is the fallback
 * for the moment before it answers, and if it fails: a list of names without
 * progress is the screen teachers had yesterday, and it beats an empty panel
 * that reads as a class with no children in it.
 */
const lettersRoster = computed<any[]>(() => (
    lettersOverview.value?.students?.length ? lettersOverview.value.students : students.value
));

// Stage options come from whatever the letters payload exposes (the class
// overview and the family/admin ones all carry `stages`); the mutation itself
// uses the frozen PUT.
//
// The fallback matters more than it looks: `group.arabic_stages` is the
// QĀʿIDAH's ladder read off the class, and it is there whichever track is on
// screen. Offered on the English track it would draw a five-rung selector for
// an alphabet with one stage, and every rung of it would write Arabic's stage.
const stageOptions = computed<any[]>(() => (
    lettersAlphabet.value === 'english'
        ? []
        : (tracker.value?.stages ?? lettersOverview.value?.stages ?? group.value?.arabic_stages ?? [])
));
// Same trap in the label: before a child is opened there is no tracker, and the
// group's own `arabic_stage` would caption the English track with a rung of the
// qāʿidah ("Vowels"). It is only an answer for the track it belongs to.
const stageFallback = computed(() => (lettersAlphabet.value === 'english' ? '' : group.value?.arabic_stage ?? ''));
const currentStageId = computed(() => tracker.value?.stage?.id ?? lettersOverview.value?.stage?.id ?? stageFallback.value);
const currentStageLabel = computed(() => tracker.value?.stage?.label ?? lettersOverview.value?.stage?.label ?? stageFallback.value);
const currentStageSummary = computed(() => tracker.value?.stage?.summary ?? lettersOverview.value?.stage?.summary ?? '');

// Arabic's four contextual forms and English's two cases, in one map: the shape
// row is the same markup either way.
const POSITIONS: Record<string, string> = {
    isolated: 'Alone', initial: 'Beginning', medial: 'Middle', final: 'End',
    upper: 'Capital', lower: 'Small',
};
const positionLabel = (id: string) => POSITIONS[id] ?? id;
const STATUS: Record<string, string> = { not_started: 'Not started', learning: 'Learning', mastered: 'Mastered' };
const statusLabel = (s: string) => STATUS[s] ?? s;
const badgeClass = (s: string) => s === 'mastered'
    ? 'bg-success-subtle text-success-emphasis'
    : (s === 'learning' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light text-muted');
const NEXT: Record<string, string> = { not_started: 'learning', learning: 'mastered', mastered: 'not_started' };

/**
 * Read the class overview for one track.
 *
 * Failure is SILENT here and only here: the roster below is still listable, a
 * child can still be opened and every letter can still be marked, so a red
 * banner over a screen that works would be noise. Every WRITE on this tab
 * reports its own failure.
 */
const loadLettersOverview = async (which: string = lettersAlphabet.value) => {
    const keepRead = classSubjects.keepRead(() => lettersAlphabet.value);
    try {
        const res = await TeacherApiService.get(`${base.value}/letters?alphabet=${which}`);
        if (keepRead()) lettersOverview.value = res.data?.data ?? null;
    } catch {
        if (keepRead()) lettersOverview.value = null;
    }
};

/**
 * Back to the class list — and re-read it.
 *
 * The teacher has just been marking, so the counts behind them are stale by
 * definition. Re-reading here rather than after every tap keeps twenty-eight
 * taps to one extra request.
 */
const closeLetters = () => {
    selected.value = null;
    openTile.value = null;
    loadLettersOverview();
};

const openLetters = async (s: any) => {
    selected.value = s;
    const keepRead = classSubjects.keepRead(() => `${lettersAlphabet.value}:${selected.value?.membership_id}`);
    openTile.value = null;
    letterError.value = '';
    tracker.value = null;
    trackerLoading.value = true;

    // Every note surface is cleared before the next child's is read. A draft
    // left in the box would be saved against whoever is opened next, which is
    // the one mistake in this screen that writes one child's record onto
    // another's.
    openDrillNote.value = null;
    drillNoteDraft.value = '';
    drillNoteError.value = '';
    dailyNotes.value = [];
    dailyNotesFailed.value = '';
    dailyNoteDraft.value = '';
    dailyNoteDate.value = todayIso;
    dailyNoteError.value = '';

    try {
        const res = await TeacherApiService.get(
            `${base.value}/members/${s.membership_id}/letters?alphabet=${lettersAlphabet.value}`
        );
        if (!keepRead()) return;
        tracker.value = res.data?.data ?? null;
        lettersMeta.value = res.data?.meta ?? lettersMeta.value;
    } catch {
        if (keepRead()) tracker.value = null;
    } finally {
        if (keepRead()) trackerLoading.value = false;
    }
    if (!keepRead()) return;

    // The daily note is the qāʿidah's, so it is only fetched on that track.
    if (lettersAlphabet.value === 'arabic') await loadDailyNotes();
};

/**
 * Change track: re-read, never merge.
 *
 * The two tracks share nothing a client could recombine — different letters,
 * different drills, a different denominator, a different direction — so an open
 * child is re-fetched rather than re-filtered, exactly as a stage change
 * re-fetches them.
 */
const switchAlphabet = async (next: string) => {
    if (next === lettersAlphabet.value) return;

    lettersAlphabet.value = next;
    openTile.value = null;
    letterError.value = '';
    stageNote.value = '';
    tracker.value = null;
    openDrillNote.value = null;
    drillNoteDraft.value = '';
    // The two tracks have different syllabi and different denominators, so the
    // class counts are re-read rather than recombined — the same rule the open
    // child follows two lines down.
    lettersOverview.value = null;

    await loadLettersOverview(next);
    if (selected.value) await openLetters(selected.value);
};

const advance = async (drill: any) => {
    if (!selected.value) return;
    marking.value = drill.id;
    letterError.value = '';
    try {
        const res = await TeacherApiService.put(
            `${base.value}/members/${selected.value.membership_id}/letters`,
            // The alphabet travels with the mark: a drill id alone cannot be
            // placed, since `ba` is a drill on one track and nothing at all on
            // the other, and the server judges it against the named one.
            { drill_id: drill.id, status: NEXT[drill.status] ?? 'learning', alphabet: lettersAlphabet.value }
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

// Mark every drill at the class's stage mastered for the open child. The server
// decides WHICH drills (the stage's syllabus, the same denominator as the
// totals), so this sends only the track.
const confirmMasterAll = ref(false);
const masteringAll = ref(false);
const masterAllNote = ref('');
const masterAllError = ref('');
/**
 * `scope` is sent explicitly because the button's words promise a scope. It used
 * to send neither, and the server marked the whole cumulative syllabus while the
 * confirmation named one stage — a class on Long Vowels had its letters, short
 * vowels, sukun, shadda and tanween marked too.
 */
const masterAll = async (scope: 'stage' | 'everything' = 'stage') => {
    if (!selected.value) return;
    masteringAll.value = true;
    masterAllError.value = '';
    masterAllNote.value = '';
    try {
        const res = await TeacherApiService.put(
            `${base.value}/members/${selected.value.membership_id}/letters/master-all`,
            { alphabet: lettersAlphabet.value, scope }
        );
        tracker.value = res.data?.data ?? tracker.value;
        masterAllNote.value = res.data?.message ?? 'Marked mastered.';
        confirmMasterAll.value = false;
    } catch (e: any) {
        // Nothing is shown as mastered unless the server said so: the tracker is
        // only replaced by a successful response.
        masterAllError.value = e?.response?.data?.message
            ?? 'That did not save. Check your connection and try again.';
    } finally {
        masteringAll.value = false;
    }
};
/**
 * What each scope would actually write, counted off the SAME payload the tiles
 * are drawn from — so the number on the button cannot disagree with the screen.
 * `stage` is the drills this stage introduces (their `stage` key equals the
 * class's); `everything` is the whole cumulative denominator.
 */
const allDrills = computed<any[]>(() =>
    (tracker.value?.letters ?? []).flatMap((l: any) => l.drills ?? []));

const everythingRemaining = computed<number>(() =>
    allDrills.value.filter((d: any) => d.status !== 'mastered').length);

const stageOwnRemaining = computed<number>(() => {
    const stage = tracker.value?.stage?.id;
    return allDrills.value.filter((d: any) => d.stage === stage && d.status !== 'mastered').length;
});

const confirmGroup = ref<string | null>(null);
const masteringGroup = ref<string | null>(null);
const groupError = ref('');

/**
 * Mark one letter group mastered. Separate from `masterAll` on purpose: a group
 * is not a stage, so "all of them" on the stage card must never reach in here,
 * and marking every throat letter must never touch the qāʿidah drills.
 */
const masterGroup = async (group: any) => {
    if (!selected.value) return;
    masteringGroup.value = group.id;
    groupError.value = '';
    try {
        const res = await TeacherApiService.put(
            `${base.value}/members/${selected.value.membership_id}/letters/master-all`,
            { alphabet: lettersAlphabet.value, scope: 'group', group: group.id }
        );
        tracker.value = res.data?.data ?? tracker.value;
        masterAllNote.value = res.data?.message ?? 'Marked mastered.';
        confirmGroup.value = null;
    } catch (e: any) {
        groupError.value = e?.response?.data?.message
            ?? 'That did not save. Check your connection and try again.';
    } finally {
        masteringGroup.value = null;
    }
};

// A confirmation or a result belongs to the child and track it was about.
watch([selected, lettersAlphabet], () => {
    confirmMasterAll.value = false;
    masterAllNote.value = '';
    masterAllError.value = '';
    confirmGroup.value = null;
    groupError.value = '';
});

const setStage = async (stage: string) => {
    savingStage.value = true;
    stageNote.value = '';
    try {
        const res = await TeacherApiService.put(`${base.value}/letters/stage`, { stage });
        stageNote.value = res.data?.message ?? 'Class stage updated.';
        if (group.value) group.value.arabic_stage = stage;
        // A narrower stage re-scopes an open tracker — and the whole class's
        // denominator with it, so the list behind the child is re-read too.
        await loadLettersOverview();
        if (selected.value) await openLetters(selected.value);
    } catch {
        stageNote.value = 'The class stage could not be changed.';
    } finally {
        savingStage.value = false;
    }
};

// ---------------------------------------------- the teacher's own words
//
// Three surfaces were asked for and two of them are here: a note on one drill,
// and a note on one day. (The hifz note is on its own tab.) Both limits come off
// the endpoint's `meta` rather than being written here, because this screen
// hardcoded the hifz quality list for two and a half weeks, one of its four
// values existed nowhere in PHP, and `repeat` — the one outcome that changes
// what happens next for a child — could not be chosen at all. Nothing failed
// loudly. A maxlength invented here fails the same quiet way: set above the
// validator's and it becomes a 422 the teacher reads as the app eating what she
// typed.
const lettersMeta = ref<any>(null);
const arabicNoteMax = computed<number>(() => Number(lettersMeta.value?.max_note_length) || 1000);
const arabicDailyNoteMax = computed<number>(() => Number(lettersMeta.value?.max_daily_note_length) || 2000);

const openDrillNote = ref<string | null>(null);
const drillNoteDraft = ref('');
const savingDrillNote = ref(false);
// Its own error, shown INSIDE the editor. `letterError` sits under the whole
// drill list, which is where a failed TAP belongs; a failed note reported there
// is a red line several rows away from the box the teacher is still looking at.
const drillNoteError = ref('');

/** Open the editor on a drill, seeded with whatever is already written there. */
const toggleDrillNote = (drill: any) => {
    if (openDrillNote.value === drill.id) {
        openDrillNote.value = null;
        return;
    }
    openDrillNote.value = drill.id;
    drillNoteDraft.value = drill.note ?? '';
    drillNoteError.value = '';
};

/**
 * Write the note WITHOUT moving the child.
 *
 * The drill's current status is sent back unchanged. The endpoint is the same
 * upsert the tile tap uses, and it requires a status — so omitting it is not an
 * option and guessing one would advance a child because a teacher wrote a
 * sentence. `mastered_at` is only ever set when it is null, so re-sending
 * `mastered` cannot move the date she finished.
 */
const saveDrillNote = async (drill: any, note?: string) => {
    if (!selected.value) return;
    savingDrillNote.value = true;
    drillNoteError.value = '';
    try {
        const res = await TeacherApiService.put(
            `${base.value}/members/${selected.value.membership_id}/letters`,
            {
                drill_id: drill.id,
                status: drill.status,
                alphabet: lettersAlphabet.value,
                note: note ?? drillNoteDraft.value,
            }
        );
        tracker.value = res.data?.data ?? tracker.value;
        lettersMeta.value = res.data?.meta ?? lettersMeta.value;
        openDrillNote.value = null;
    } catch (e: any) {
        // The editor stays OPEN and the draft stays in it. A teacher who has
        // just typed three sentences about a child and hit a dead connection
        // must not lose them to a closing panel.
        // apiErrorText, not `data.message`: the likeliest refusal here is the
        // length rule, and that arrives as a validation BAG (`data.note[]`)
        // with no top-level message at all — read naively it renders as blank
        // and the save looks like it silently did nothing.
        drillNoteError.value = apiErrorText(e, 'That note did not save. Check your connection and try again.');
    } finally {
        savingDrillNote.value = false;
    }
};

/**
 * Remove a note deliberately.
 *
 * A PRESENT empty value is what the endpoint reads as a clear; an ABSENT key
 * means "I am not speaking about the note" and leaves it alone. That is the
 * whole reason the two are distinguished server-side, and this button is the
 * only thing in the screen that sends the first one.
 */
const clearDrillNote = async (drill: any) => {
    await saveDrillNote(drill, '');
};

// ---------------------------------------------------- the daily Arabic note
const dailyNotes = ref<any[]>([]);
const dailyNotesLoading = ref(false);
// Holds the REASON, not a flag: an empty string is a healthy list. The
// message belongs beside the list it is explaining, not in the save error
// above it, or a teacher reads one failure twice and looks for two problems.
const dailyNotesFailed = ref('');
const dailyNoteDate = ref<string>(todayIso);
const dailyNoteDraft = ref('');
const dailyNoteError = ref('');
const savingDailyNote = ref(false);
const deletingDailyNote = ref<number | null>(null);
const today = todayIso;

/** The note already filed for the day the teacher is looking at, if there is one. */
const dailyNoteExisting = computed(
    () => dailyNotes.value.find((n: any) => (n.session_date ?? '').slice(0, 10) === dailyNoteDate.value) ?? null
);

/**
 * A stored day, read LITERALLY.
 *
 * `new Date('2026-09-16')` is UTC midnight and renders as the 15th for every
 * reader west of UTC. This module has already shown a parent the wrong day that
 * way once (`leftDay` below carries the same note), and a note about the wrong
 * lesson is worse than no note.
 */
const longDate = (iso: string | null): string => {
    if (!iso) return '';
    const [y, m, d] = iso.slice(0, 10).split('-').map(Number);
    if (!y || !m || !d) return iso;
    return new Date(y, m - 1, d).toLocaleDateString(undefined, {
        weekday: 'long', month: 'short', day: 'numeric', year: 'numeric',
    });
};

const loadDailyNotes = async () => {
    if (!selected.value) return;
    dailyNotesLoading.value = true;
    dailyNotesFailed.value = '';
    dailyNoteError.value = '';
    try {
        const res = await TeacherApiService.get(
            `${base.value}/members/${selected.value.membership_id}/arabic-notes`
        );
        dailyNotes.value = rowsOf(res.data?.data);
        lettersMeta.value = res.data?.meta ?? lettersMeta.value;
    } catch (e: any) {
        // NOT a silent empty list. "No daily notes for this student yet" and
        // "we could not ask" are different sentences, and this module has
        // already been bitten by a screen that rendered a failed read as a
        // confident zero. A teacher who is told the child has no notes when in
        // fact the request failed will write the day again.
        dailyNotes.value = [];
        dailyNotesFailed.value = apiErrorText(e, 'They could not be loaded.');
    } finally {
        dailyNotesLoading.value = false;
    }
};

/**
 * Seed the box from the day being looked at.
 *
 * The endpoint UPSERTS on (student, day). Without this, a teacher who picks a
 * date that already has a note types into an empty box and silently replaces
 * what somebody wrote — the save says 200 and the old words are simply gone.
 * Showing them is what turns an overwrite into a correction.
 */
watch([dailyNoteDate, dailyNotes], () => {
    dailyNoteDraft.value = dailyNoteExisting.value?.note ?? '';
});

const saveDailyNote = async () => {
    if (!selected.value || !dailyNoteDraft.value.trim()) return;
    savingDailyNote.value = true;
    dailyNoteError.value = '';
    try {
        await TeacherApiService.put(
            `${base.value}/members/${selected.value.membership_id}/arabic-notes`,
            { session_date: dailyNoteDate.value, note: dailyNoteDraft.value }
        );
        // Re-read rather than splice the response in: the list is ordered by day
        // and an edited day moves within it, so building the new list here is a
        // second place that can disagree with the server about what is filed.
        await loadDailyNotes();
    } catch (e: any) {
        dailyNoteError.value = apiErrorText(e, 'That note did not save. Check your connection and try again.');
    } finally {
        savingDailyNote.value = false;
    }
};

/** Bring a filed day back into the editor, which is also how it is corrected. */
const editDailyNote = (n: any) => {
    dailyNoteDate.value = (n.session_date ?? '').slice(0, 10);
    dailyNoteDraft.value = n.note ?? '';
    dailyNoteError.value = '';
};

const deleteDailyNote = async (n: any) => {
    if (!selected.value) return;
    // Deleting a teacher's words about a child is not undoable and the row is
    // gone rather than blanked, so it is asked about first.
    if (!window.confirm(`Remove the note for ${longDate(n.session_date)}? This cannot be undone.`)) return;

    deletingDailyNote.value = n.id;
    dailyNoteError.value = '';
    try {
        await TeacherApiService.delete(
            `${base.value}/members/${selected.value.membership_id}/arabic-notes/${n.id}`
        );
        await loadDailyNotes();
    } catch (e: any) {
        dailyNoteError.value = apiErrorText(e, 'That note could not be removed.');
    } finally {
        deletingDailyNote.value = null;
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

// Adding to the school's vocabulary from the teacher's own screen.
const newSkill = ref<{ label: string; polarity: string; default_points: number }>({
    label: '', polarity: 'positive', default_points: 1,
});
const addingSkill = ref(false);
const skillError = ref('');

// What the chosen skill is normally worth, shown as the Points placeholder so a
// teacher can see what leaving it blank will do before they leave it blank.
const selectedSkillPoints = computed<number | null>(() => {
    const sk = skills.value.find((s: any) => String(s.id) === String(awardSkillId.value));
    if (!sk) return null;
    const n = Math.abs(sk.default_points ?? 1);
    return sk.polarity === 'negative' ? -n : n;
});

/**
 * Add a skill to the school's vocabulary and select it straight away.
 *
 * Selecting it is the point: a teacher adds "Helped without being asked"
 * BECAUSE a child just did it, and making them then find it in the dropdown is
 * a step that exists for no one.
 */
const createSkill = async () => {
    if (!newSkill.value.label) return;
    addingSkill.value = true;
    skillError.value = '';
    try {
        const res = await TeacherApiService.post(
            `/api/teacher/masjids/${masjidId.value}/behavior-skills`,
            {
                label: newSkill.value.label,
                polarity: newSkill.value.polarity,
                // Stored as the magnitude; polarity carries the direction, which
                // is why a school can legitimately run "Disruption, 1, negative".
                default_points: Math.abs(newSkill.value.default_points || 1),
                is_active: true,
            }
        );
        const created = res.data?.data;
        if (created?.id) {
            skills.value = withSkillInserted(skills.value, created);
            awardSkillId.value = created.id;
        }
        newSkill.value = { label: '', polarity: 'positive', default_points: 1 };
    } catch (e: any) {
        skillError.value = e?.response?.data?.data?.label?.[0]
            ?? e?.response?.data?.data?.default_points?.[0]
            ?? 'That could not be added.';
    } finally {
        addingSkill.value = false;
    }
};

// Every current student's running total, from the server, which sums the same
// awards the family summary does. Not computed here from `awards`: that list is
// one page of one child, and a total built from a page is a wrong number.
const pointsTotals = ref<any>(null);
const pointsTotalsFailed = ref(false);
// The week the totals are showing, as the server's first-day date; null = the
// week in progress. Stepping is by the server's own `previous` / `next`, never by
// arithmetic on the browser's clock (the browser's zone is not the school's).
const loadPointsTotals = async (week: string | null = null) => {
    pointsTotalsFailed.value = false;
    try {
        const res = await TeacherApiService.get(
            `${base.value}/awards/totals${week ? `?week=${encodeURIComponent(week)}` : ''}`
        );
        pointsTotals.value = res.data?.data ?? null;
        // The server's word on how this class reads (another teacher of the class
        // may have changed it since this screen loaded).
        if (group.value && pointsTotals.value?.points_period) group.value.points_period = pointsTotals.value.points_period;
    } catch {
        // Hidden rather than shown as zeros: a failed read is not "no points".
        pointsTotals.value = null;
        pointsTotalsFailed.value = true;
    }
};
// Landing on the Points tab from the email link (?tab=points) is not a tab CHANGE, so the watch on
// `activeTab` below never fires for it: load what the tab shows here, on the reported week.
onMounted(() => {
    if (activeTab.value !== 'points') return;
    loadSkills();
    loadPointsTotals(linkedPointsWeek);
});
const selectedPointsTotal = computed(() => pointsTotals.value?.students
    ?.find((t: any) => String(t.membership_id) === String(pointsMembership.value)) ?? null);

// The weekly reset (T-003.2). The class's own choice, saved on the class so every
// teacher of it reads the same thing; `group` carries it from the class payload.
const pointsPeriod = computed<string>(() => group.value?.points_period ?? pointsTotals.value?.points_period ?? 'running');
const pointsWeekly = computed(() => isWeekly(pointsPeriod.value));
const selectedHeadline = computed(() => pointsHeadline(pointsPeriod.value, selectedPointsTotal.value));
const classHeadline = computed(() => pointsHeadline(pointsPeriod.value, pointsTotals.value?.class));
const pointsWeekLabel = computed(() => pointsTotals.value?.week
    ? weekRangeLabel(pointsTotals.value.week.start, pointsTotals.value.week.end)
    : '');
const savingPeriod = ref(false);
const periodError = ref('');
const setPointsPeriod = async (input: HTMLInputElement) => {
    const weekly = input.checked;
    savingPeriod.value = true;
    periodError.value = '';
    try {
        const res = await TeacherApiService.put(`${base.value}/points-period`, {
            points_period: weekly ? 'weekly' : 'running',
        });
        // The server's word, not the checkbox's: what is stored is what is shown.
        const stored = res.data?.data?.points_period ?? (weekly ? 'weekly' : 'running');
        if (group.value) group.value.points_period = stored;
        await loadPointsTotals(pointsTotals.value?.week?.is_current === false ? pointsTotals.value.week.start : null);
    } catch (e: any) {
        periodError.value = apiErrorText(e, 'That could not be saved.');
        // The switch shows what is actually stored, not what was tapped.
        input.checked = !weekly;
    } finally {
        savingPeriod.value = false;
    }
};

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
            const picker = pickerFrom(s, awardSkillId.value);
            skills.value = picker.skills;
            awardSkillId.value = picker.selectedId;
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
            const picker = pickerFrom(s, awardSkillId.value);
            skills.value = picker.skills;
            awardSkillId.value = picker.selectedId;
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
        loadPointsTotals();
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
        loadPointsTotals();
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
/**
 * What the server says about hifz: the qualities it will accept, the note
 * ceiling it enforces. Populated by `loadHifz` from the `meta` block the
 * endpoint has always returned.
 */
const hifzMeta = ref<any>(null);

/**
 * The qualities the API will actually accept, taken from the server.
 *
 * WHY THIS IS BOUND RATHER THAN LISTED. Until 2026-09-16 this screen hardcoded
 * four <option> tags, and one of them — `needs_work` — was a value that exists
 * nowhere in the backend. `StoreHifzEntryRequest` validates against
 * `HifzEntry::QUALITIES`, so a teacher choosing it got a 422 and lost the
 * recording; `repeat`, the outcome that actually changes what happens next for
 * that child, was unreachable from this screen entirely. Live 2.5 weeks.
 *
 * Correcting the one string would have fixed the instance and left the
 * mechanism: the next quality added or renamed backend-side would break this
 * screen again with nothing to complain. `GroupHifzTab.vue` — the admin screen
 * over the same data — already binds the list from the server, so this is that
 * proven pattern rather than a new idea. The literal below is a FALLBACK for a
 * first paint before any student is chosen, not the source of truth.
 */
const hifzQualities = computed<string[]>(
    () => hifzMeta.value?.qualities ?? ['excellent', 'good', 'fair', 'repeat']);

/** The ceiling the request boundary enforces, so the input cannot invite a 422. */
const hifzNoteMax = computed<number>(() => Number(hifzMeta.value?.max_note_length) || 1000);

const hifzForm = ref({
    kind: 'sabak',
    surah: null as number | null,
    from_ayah: null as number | null,
    to_ayah: null as number | null,
    quality: 'good',
    // The API has accepted this since the hifz module shipped and no screen
    // ever offered it, so the field has been carrying provenance nobody could
    // read: a bulk office backfill of 25 surahs for one child on 2026-09-16
    // wrote its explanation here and a teacher looking at that record saw 25
    // excellent recitations with no sign that nobody heard them.
    note: '',
    // WHEN it was heard. The API has accepted `recited_at` since the module
    // shipped — and documents that it may be backdated, "a teacher entering the
    // morning's ḥalaqa after ʿaṣr" — but no screen ever sent it, so every entry
    // was stamped at the moment of typing. A teacher writing up Saturday's
    // ḥalaqa on Sunday had no way to say so, and the child's sabak history
    // recorded the wrong day.
    //
    // Empty means today, and today sends nothing: an entry made now should
    // carry the real time it was made, not midnight.
    recited_on: '',
    // "Whole surah": no āyah range is sent; the server records 1 to the last.
    whole_surah: false,
});

/** Āyāt in the chosen surah — the ceiling both inputs are bounded by. */
const surahAyahs = computed(() =>
    surahs.value.find((s) => s.number === hifzForm.value.surah)?.ayahs ?? 286);

const ayahCount = computed(() => {
    if (hifzForm.value.whole_surah) {
        // Only when the index actually loaded — never the 286 fallback above.
        return surahs.value.find((s) => s.number === hifzForm.value.surah)?.ayahs ?? 0;
    }
    const { from_ayah: f, to_ayah: t } = hifzForm.value;
    return f && t && t >= f ? t - f + 1 : 0;
});

const hifzValid = computed(() =>
    !!hifzForm.value.surah && (hifzForm.value.whole_surah || (ayahCount.value > 0
    && (hifzForm.value.to_ayah ?? 0) <= surahAyahs.value)));

const loadSurahs = async () => {
    if (surahs.value.length) return;
    try {
        const res = await TeacherApiService.get(`/api/teacher/masjids/${masjidId.value}/quran-surahs`);
        surahs.value = res.data?.data ?? [];
    } catch {
        // The form still submits by number if the index cannot be reached.
    }
};

// ---------------------------------------------- changing a recorded line
// "Edit entry" (owner, 2026-10-07). The form above IS the editor: the line is
// loaded into it, and saving sends ONE request, `POST .../hifz/{id}/correct`.
// The server corrects the entry in place and keeps the line as it stood as a
// struck copy, so the history of corrections is kept and the entry keeps its
// place in the order a child's position is read from. (The first build recorded
// a new line and struck the old one from here; a re-recorded line gets a new id,
// which could move a child's position backwards. See HifzEntriesController::correct.)
const hifzEditing = ref<any | null>(null);
/** The form as it stood before a line was loaded into it, put back afterwards. */
let hifzFormBefore: any = null;

/** A line as the list writes it, for the "Changing this line" box. */
const hifzLine = (h: any): string => {
    const portion = h.whole_surah
        ? `all of ${h.from?.surah_name ?? `Surah ${h.from?.surah}`}`
        : `${ayah(h.from)} → ${ayah(h.to)}`;
    return `${hifzKindLabel(h.kind)}: ${portion} · ${hifzQualityLabel(h.quality)} · ${hifzDayLabel(h.recited_at, undefined, h.created_at)}`;
};

/**
 * The form records ONE surah, so only a line inside one surah can be loaded
 * into it. A line across two (the API allows it) keeps its note editor and
 * Remove.
 */
const hifzEditable = (h: any): boolean =>
    !!h.from?.surah && !!h.from?.ayah && !!h.to?.ayah && h.from.surah === h.to?.surah;

/** The day a line was heard, as the date box holds it; '' when it is unknown (core/helpers/hifzDay). */
const hifzDay = (h: any): string => hifzDayOf(h.recited_at, h.created_at);

const startHifzEdit = (entry: any) => {
    if (hifzBusy.value || hifzEditing.value || !hifzEditable(entry)) return;
    openHifzNote.value = null;
    openHifzCopy.value = null;
    hifzCopyDone.value = null;
    hifzError.value = '';
    hifzFormBefore = { ...hifzForm.value };
    hifzForm.value = {
        kind: entry.kind,
        surah: entry.from.surah,
        // A whole surah is ticked rather than typed out, as it was recorded.
        from_ayah: entry.whole_surah ? null : entry.from.ayah,
        to_ayah: entry.whole_surah ? null : entry.to.ayah,
        quality: entry.quality,
        note: entry.note ?? '',
        recited_on: hifzDay(entry),
        whole_surah: !!entry.whole_surah,
    };
    hifzEditing.value = entry;
    nextTick(() => document.getElementById('hifz-form')?.scrollIntoView?.({ block: 'start', behavior: 'smooth' }));
};

/** Leave edit mode and put the form back as it was (the kept surah included). */
const endHifzEdit = () => {
    hifzEditing.value = null;
    if (hifzFormBefore) hifzForm.value = hifzFormBefore;
    hifzFormBefore = null;
};

const cancelHifzEdit = () => {
    if (hifzBusy.value) return;
    hifzError.value = '';
    endHifzEdit();
};

/**
 * Save a changed line: one request, and the server does the rest.
 *
 *  - Nothing changed: nothing is sent.
 *  - Otherwise the whole line goes to `.../correct`: type, surah, āyāt,
 *    quality and the note (always sent; blank clears it).
 *  - The day is sent ONLY when it was changed (hifzDayToSend). Left alone, the
 *    request says nothing about it and the entry keeps the exact moment it was
 *    heard.
 *
 * A refusal changes nothing on the server: the form stays as typed, in edit
 * mode, with the reason shown.
 */
const saveHifzEdit = async () => {
    const entry = hifzEditing.value;
    if (!entry || hifzBusy.value || !hifzValid.value || !hifzMembership.value) return;
    const f = hifzForm.value;
    const wholeNow = !!f.whole_surah;
    const dayNow = f.recited_on || todayIso;
    const dayChanged = dayNow !== (hifzDay(entry) || todayIso);
    // "Whole surah" ticked on a line that was a whole surah is the same āyāt,
    // judged without the surah list (which may not have loaded); otherwise the
    // two numbers decide.
    const rangeChanged = f.surah !== entry.from.surah
        || (wholeNow ? !entry.whole_surah : (f.from_ayah !== entry.from.ayah || f.to_ayah !== entry.to.ayah));
    const noteNow = f.note.trim();
    const changed = f.kind !== entry.kind || rangeChanged || f.quality !== entry.quality || dayChanged
        || noteNow !== (entry.note ?? '').trim();

    if (!changed) {
        endHifzEdit();
        return;
    }

    recordingHifz.value = true;
    hifzError.value = '';
    try {
        const res = await TeacherApiService.post(`${base.value}/hifz/${entry.id}/correct`, {
            kind: f.kind,
            from_surah: f.surah,
            to_surah: f.surah,
            ...(wholeNow ? { whole_surah: 1 } : { from_ayah: f.from_ayah, to_ayah: f.to_ayah }),
            quality: f.quality,
            note: noteNow,
            ...(dayChanged ? { recited_at: hifzDayToSend(dayNow) } : {}),
        });
        const saved = res.data?.data;
        endHifzEdit();
        if (saved && saved.id === entry.id && !saved.corrected_at) {
            // The server's line, in the place the old one held. A changed day can
            // move it in the list, so the list is read again in that case. (A line
            // that comes back marked struck is never drawn as live: the list is read.)
            const at = hifz.value.findIndex((h) => h.id === entry.id);
            if (at >= 0 && !dayChanged) hifz.value.splice(at, 1, saved);
            else await loadHifz();
        } else {
            // An answer that does not say what was stored: show what the server holds.
            await loadHifz();
        }
    } catch (e: any) {
        // Nothing has changed on the server. The form keeps what was typed.
        hifzError.value = apiErrorText(e, 'The change was not saved. Check your connection and try again.');
    } finally {
        recordingHifz.value = false;
    }
};

// ---------------------------------------------- the note on a recitation
// One editor open at a time, on the line it belongs to, as the drill notes on the
// Letters tab work. Its error is said under the box the teacher is looking at.
const openHifzNote = ref<string | number | null>(null);
const hifzNoteDraft = ref('');
const savingHifzNote = ref(false);
const hifzNoteError = ref('');

/** Open the editor on a line, seeded with what is already written there. */
const toggleHifzNote = (entry: any) => {
    // A save in flight owns the draft and the error line; a copy owns the note
    // it is sending. Neither is handed to another line under it. Nor while a
    // line is loaded into the form above: Save or Cancel there first.
    if (hifzBusy.value || hifzEditing.value) return;
    if (openHifzNote.value === entry.id) {
        openHifzNote.value = null;
        return;
    }
    openHifzCopy.value = null;
    openHifzNote.value = entry.id;
    hifzNoteDraft.value = entry.note ?? '';
    hifzNoteError.value = '';
};

/**
 * Write the note and NOTHING else. The endpoint takes the note alone, so a
 * sentence about a recitation cannot move the child or change what was heard.
 * `note` is always sent: a present, empty value is the deliberate clear.
 */
const saveHifzNote = async (entry: any, note?: string) => {
    if (hifzBusy.value) return;
    savingHifzNote.value = true;
    hifzNoteError.value = '';
    try {
        const res = await TeacherApiService.put(`${base.value}/hifz/${entry.id}`, { note: note ?? hifzNoteDraft.value });
        const saved = res.data?.data;
        const row = hifz.value.find((h) => h.id === entry.id);
        if (saved && saved.id === entry.id && 'note' in saved) {
            // The server's words, not the draft: it trims, and it is what the
            // family will read.
            if (row) row.note = saved.note ?? null;
        } else if (row) {
            // An answer that does not say what was stored is not a save the
            // screen can vouch for: show what the server holds.
            await loadHifz();
        }
        // Only the editor this save belongs to. The teacher may have opened
        // another line's while it was in flight.
        if (openHifzNote.value === entry.id) openHifzNote.value = null;
    } catch (e: any) {
        // The editor stays OPEN with the draft in it. apiErrorText, not
        // `data.message`: the likeliest refusal is the length rule, which
        // arrives as a validation bag with no top-level message.
        const why = apiErrorText(e, 'That note did not save. Check your connection and try again.');
        // Under the box it belongs to. Nothing on this tab can move the editor
        // while a save is in flight (hifzBusy), so this is the same line; the
        // check is for a list that was replaced some other way, and then the
        // refusal is said at the top rather than under a stranger's line.
        if (openHifzNote.value === entry.id) hifzNoteError.value = why;
        else hifzError.value = why;
    } finally {
        savingHifzNote.value = false;
    }
};

// ------------------------------------- the same line for other students
// "Copy notes to other students" (owner, 2026-10-07). A note cannot exist apart
// from a recitation, so copying it to another student RECORDS that line for
// them: the same portion, type, quality and day, with the note. Each copy is an
// ordinary `POST .../hifz`, so it is validated, attributed and struck exactly
// like a line typed by hand, and the teacher realm gains no verb for it.
const openHifzCopy = ref<string | number | null>(null);
const hifzCopyTo = ref<(string | number)[]>([]);
const copyingHifz = ref(false);
const hifzCopyError = ref('');
/** What the last copy did, said under the line it was copied FROM. */
const hifzCopyDone = ref<{ id: string | number; text: string } | null>(null);

/** Everyone in the class but the student whose log is open. */
const hifzClassmates = computed(() =>
    students.value.filter((s) => String(s.membership_id) !== String(hifzMembership.value)));

/**
 * A note is saving, a line is being copied, a recitation is being recorded or
 * corrected, or a line is being removed (a line opened in the form while its
 * removal was still in flight stayed in the form after it was gone).
 * While it is, the student cannot be changed, no other line's editor or copy
 * panel can be opened and nothing else can be written: each answers to the list
 * on screen, and its answer must come back to the line and the student it left
 * from. Record is in it because it reloads the list when it is done, which
 * closed an open note editor under a save still in flight and lost its draft.
 */
const hifzBusy = computed(() => savingHifzNote.value || copyingHifz.value || recordingHifz.value || removingHifz.value !== null);

const toggleHifzCopy = (entry: any) => {
    if (hifzBusy.value || hifzEditing.value) return;
    if (openHifzCopy.value === entry.id) {
        openHifzCopy.value = null;
        return;
    }
    // One panel on a line at a time: the note editor gives way.
    openHifzNote.value = null;
    openHifzCopy.value = entry.id;
    hifzCopyTo.value = [];
    hifzCopyError.value = '';
    hifzCopyDone.value = null;
};

const toggleHifzCopyTo = (membershipId: string | number) => {
    hifzCopyTo.value = hifzCopyTo.value.includes(membershipId)
        ? hifzCopyTo.value.filter((id) => id !== membershipId)
        : [...hifzCopyTo.value, membershipId];
};

/**
 * Record this line for each chosen student, one request each, in turn.
 *
 * Not all-or-nothing, and it says what happened by name: a copy that reached
 * three students and failed for one reports the three and keeps the one ticked
 * with the server's reason, so "Copy" again retries only her. The range is sent
 * as the four coordinates the line holds (a whole surah included), never as
 * "whole surah", so the copy is the same āyāt whatever the original's flag was.
 */
const copyHifz = async (entry: any) => {
    if (hifzBusy.value) return;
    // Only students who are offered in THIS panel: classmates of the student
    // whose log is open, never that student, never an id left from another panel.
    const offered = new Set(hifzClassmates.value.map((s) => s.membership_id));
    const chosen = hifzCopyTo.value.filter((id) => offered.has(id));
    if (!chosen.length) return;
    // The line as it stands NOW, read once. Every chosen student gets these same
    // words even if the line on screen changes while the copies go out.
    const line = {
        kind: entry.kind,
        from_surah: entry.from?.surah,
        from_ayah: entry.from?.ayah,
        to_surah: entry.to?.surah,
        to_ayah: entry.to?.ayah,
        quality: entry.quality,
        note: entry.note,
        ...(entry.recited_at ? { recited_at: entry.recited_at } : {}),
    };
    const from = hifzMembership.value;
    copyingHifz.value = true;
    hifzCopyError.value = '';
    hifzCopyDone.value = null;
    const copied: string[] = [];
    const failed: { id: string | number; text: string }[] = [];
    try {
        for (const id of chosen) {
            const student = students.value.find((s) => s.membership_id === id);
            const who = student ? name(student.contact) : 'a student';
            try {
                await TeacherApiService.post(`${base.value}/hifz`, { membership_id: id, ...line });
                copied.push(who);
            } catch (e: any) {
                failed.push({ id, text: `Not copied for ${who}: ${apiErrorText(e, 'the line could not be recorded.')}` });
            }
        }
    } finally {
        copyingHifz.value = false;
    }
    const said = [
        copied.length ? `Copied to ${copied.join(', ')}.` : '',
        ...failed.map((f) => f.text),
    ].filter(Boolean).join(' ');
    // The panel this run left from, still on the same student's log. Nothing on
    // the tab can change either while a copy is in flight (hifzBusy); if the list
    // was replaced some other way, the outcome is said at the top of the tab and
    // no selection is written into a panel that did not make it.
    if (openHifzCopy.value !== entry.id || hifzMembership.value !== from) {
        if (said) hifzError.value = said;
        return;
    }
    hifzCopyTo.value = failed.map((f) => f.id);
    if (copied.length) hifzCopyDone.value = { id: entry.id, text: `Copied to ${copied.join(', ')}.` };
    if (failed.length) {
        hifzCopyError.value = failed.map((f) => f.text).join(' ');
    } else {
        openHifzCopy.value = null;
    }
};

// Counts the loads. Choosing student B and then A can bring A's lines back first
// and B's last; without this the list on screen was B's under A's name, and a
// note edited or a line removed there was B's.
let hifzSeq = 0;

const loadHifz = async () => {
    const seq = ++hifzSeq;
    hifz.value = [];
    // Another student's list: an editor left open would sit on nobody's line,
    // and a line loaded into the form would be saved against the wrong child.
    if (hifzEditing.value) endHifzEdit();
    openHifzNote.value = null;
    openHifzCopy.value = null;
    hifzCopyDone.value = null;
    if (!hifzMembership.value) return;
    hifzLoading.value = true;
    hifzError.value = '';
    try {
        const res = await TeacherApiService.get(`${base.value}/members/${hifzMembership.value}/hifz`);
        // An older student's answer, arriving late: the newer load owns the list.
        if (seq !== hifzSeq) return;
        hifz.value = rowsOf(res.data?.data);
        // The endpoint has always sent this and this screen has always discarded
        // it, which is the whole reason the quality dropdown drifted out of sync
        // with the backend and offered a value the API rejects.
        hifzMeta.value = res.data?.meta ?? null;
    } catch {
        if (seq === hifzSeq) hifzError.value = 'The recitation log could not be loaded.';
    } finally {
        if (seq === hifzSeq) hifzLoading.value = false;
    }
};

const recordHifz = async () => {
    if (!hifzMembership.value || !hifzValid.value || hifzBusy.value || hifzEditing.value) return;
    recordingHifz.value = true;
    hifzError.value = '';
    try {
        await TeacherApiService.post(`${base.value}/hifz`, {
            membership_id: hifzMembership.value,
            kind: hifzForm.value.kind,
            // The API's interval may cross surahs; this form deliberately does
            // not, so both ends carry the one chosen surah.
            from_surah: hifzForm.value.surah,
            to_surah: hifzForm.value.surah,
            // A whole surah sends no āyāt at all, so the server's index is the
            // only source of where it ends.
            ...(hifzForm.value.whole_surah
                ? { whole_surah: 1 }
                : { from_ayah: hifzForm.value.from_ayah, to_ayah: hifzForm.value.to_ayah }),
            quality: hifzForm.value.quality,
            major_mistakes: 0,
            minor_mistakes: 0,
            // Omitted entirely when blank rather than sent as an empty string:
            // the column means "nobody wrote here", and '' would assert that a
            // teacher wrote nothing, which is a different claim.
            ...(hifzForm.value.note.trim() ? { note: hifzForm.value.note.trim() } : {}),
            // Sent only when it is NOT today: an entry recorded now should keep
            // the time it happened. A chosen day goes as noon UTC of that day
            // (hifzDayToSend): the bare date this used to send is stored as
            // midnight UTC, which every list then showed as the day BEFORE.
            ...(hifzForm.value.recited_on && hifzForm.value.recited_on !== todayIso
                ? { recited_at: hifzDayToSend(hifzForm.value.recited_on) }
                : {}),
        });
        // The surah is KEPT: the next entry for this child is usually the next
        // few āyāt of the same one.
        hifzForm.value.from_ayah = hifzForm.value.to_ayah = null;
        // Unticked after each record: the next entry is usually a few āyāt, and
        // a box left ticked would record a second whole surah by accident.
        hifzForm.value.whole_surah = false;
        // The NOTE is cleared, unlike the surah. A note is about the portion
        // just recorded; carrying it forward would silently attach one child's
        // "struggled with the waqf" to the next portion, or to the next child
        // if the teacher switches student without noticing the field is still
        // filled. Keeping the surah saves typing; keeping the note fabricates a
        // record.
        hifzForm.value.note = '';
        await loadHifz();
    } catch (e: any) {
        hifzError.value = e?.response?.data?.message || 'That recitation could not be recorded.';
    } finally {
        recordingHifz.value = false;
    }
};

const removeHifz = async (entry: any) => {
    if (hifzBusy.value || hifzEditing.value) return;
    removingHifz.value = entry.id;
    try {
        await TeacherApiService.delete(`${base.value}/hifz/${entry.id}`);
        hifz.value = hifz.value.filter((h) => h.id !== entry.id);
        if (openHifzNote.value === entry.id) openHifzNote.value = null;
        if (openHifzCopy.value === entry.id) openHifzCopy.value = null;
        if (hifzEditing.value?.id === entry.id) endHifzEdit();
    } catch {
        hifzError.value = 'That entry could not be removed.';
    } finally {
        removingHifz.value = null;
    }
};

// ============================================================ PHOTOS + VIDEO
// Shared by the class story and messages. Media goes as multipart, in the two
// top-level bags the server reads for both surfaces.
//
// TWO BAGS, and the split happens HERE rather than in the picker: `images` and
// `videos` are validated against different allowlists, different size ceilings
// (8MB against 100MB) and different counts, so putting a clip in the `images`
// bag is a 422 the teacher cannot act on. The picker holds one list because a
// teacher choosing "three photos and the recital" should not need two buttons.
const withPhotos = (fields: Record<string, string | number>, media: File[]): FormData => {
    const form = new FormData();
    Object.entries(fields).forEach(([key, value]) => form.append(key, String(value)));
    media.forEach((file) => form.append(
        isVideoFile(file) ? 'videos[]' : 'images[]',
        file,
        file.name,
    ));
    return form;
};

// nginx answers an oversized request itself, as HTML, so apiErrorText would
// only have axios's "status code 413" to show.
const photoErrorText = (e: any, fallback: string, noun: 'post' | 'message' = 'post'): string => uploadErrorText(e, fallback, noun);

// ============================================================ STORY
const posts = ref<any[]>([]);
/** `meta.story_reads` of the feed: receipts on or off, and the parents who cannot be counted. */
const storyReads = ref<{ enabled: boolean; unreachable_count?: number }>({ enabled: false });
const postsLoading = ref(false);
const composeTitle = ref('');
const composeBody = ref('');
const storyPhotos = ref<File[]>([]);

// What the pickers may take, from the SERVER's own `meta` (the posts and threads
// lists carry it). The pickers ran on their built-in defaults before, so a limit
// changed on the server (three videos, since 2026-10-01) never reached this screen.
// Empty until a list has loaded: the picker's defaults cover that moment.
const storyMedia = ref<Record<string, number | string>>({});
const messageMedia = ref<Record<string, number | string>>({});
const posting = ref(false);
const postError = ref('');
const removingPost = ref<string | number | null>(null);

// "Send later" for a story: the school's zone and horizon come from the server's meta.
const storyScheduling = ref<{ timezone: string; max_days_ahead: number } | null>(null);
const storyLater = useSendLater(
    computed(() => storyScheduling.value?.timezone),
    computed(() => storyScheduling.value?.max_days_ahead),
);
const scheduledStories = ref<ScheduledRow[]>([]);
const scheduledBusy = ref(false);
const scheduledError = ref('');

const loadPosts = async () => {
    postsLoading.value = true;
    try {
        const res = await TeacherApiService.get(`${base.value}/posts`);
        posts.value = rowsOf(res.data?.data);
        storyReads.value = res.data?.meta?.story_reads ?? { enabled: false };
        storyScheduling.value = res.data?.meta?.scheduling ?? null;
        storyMedia.value = pickerLimits(res.data?.meta, 'max_images_per_post', 'max_videos_per_post');
        await loadScheduledStories();
    } catch {
        posts.value = [];
    } finally {
        postsLoading.value = false;
    }
};

/** The stories written and waiting, or refused at release. Nothing to show is not an error. */
const loadScheduledStories = async () => {
    try {
        const res = await TeacherApiService.get(`${base.value}/posts?scheduled=1`);
        scheduledStories.value = rowsOf(res.data?.data).map(storyRow);
    } catch {
        scheduledStories.value = [];
    }
};

/** One Scheduled-list action, then both lists again (Send now moves a story into the feed). */
const runScheduledStory = async (action: () => Promise<unknown>, failure: string) => {
    scheduledBusy.value = true;
    scheduledError.value = '';
    try {
        await action();
        await loadPosts();
    } catch (e: any) {
        scheduledError.value = apiErrorText(e, failure);
    } finally {
        scheduledBusy.value = false;
    }
};

const sendStoryNow = (row: ScheduledRow) =>
    runScheduledStory(() => TeacherApiService.put(`${base.value}/posts/${row.id}`, { send_now: true }), 'That story could not be sent.');

const cancelStory = (row: ScheduledRow) =>
    runScheduledStory(() => TeacherApiService.delete(`${base.value}/posts/${row.id}`), 'That story could not be cancelled.');

const saveStory = (row: ScheduledRow, fields: { heading: string; body: string; sendAt: string | null }) =>
    runScheduledStory(() => TeacherApiService.put(`${base.value}/posts/${row.id}`, {
        title: fields.heading || null,
        body: fields.body,
        ...(fields.sendAt ? { send_at: fields.sendAt } : {}),
    }), 'That story could not be saved.');

const submitPost = async () => {
    if (!composeBody.value) return;
    posting.value = true;
    postError.value = '';
    try {
        if (storyPhotos.value.length) {
            const fields: Record<string, string> = { body: composeBody.value, ...storyLater.fields() };
            if (composeTitle.value) fields.title = composeTitle.value;
            await TeacherApiService.postForm(`${base.value}/posts`, withPhotos(fields, storyPhotos.value));
        } else {
            await TeacherApiService.post(`${base.value}/posts`, {
                title: composeTitle.value || null,
                body: composeBody.value,
                ...storyLater.fields(),
            });
        }
        composeTitle.value = '';
        composeBody.value = '';
        storyPhotos.value = [];
        storyLater.reset();
        await loadPosts();
    } catch (e: any) {
        postError.value = photoErrorText(e, 'The post could not be published.');
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

// Edit a story AFTER it is sent (W7-2a): title and text in place, PUT with those two fields
// only. The server stamps "Edited", tells nobody, and answers with the story, which replaces
// the row; a failure stays beside the form.
const {
    editingId: editingStoryId, busy: storyEditBusy, error: storyEditError,
    start: startStoryEdit, cancel: cancelStoryEdit, submit: submitStoryEdit,
} = useStoryEdit<any>(
    async (post, fields) => {
        const res = await TeacherApiService.put(`${base.value}/posts/${post.id}`, { title: fields.title || null, body: fields.body });

        if (!res.data?.data) throw new Error('That story could not be saved.');

        return res.data.data;
    },
    (updated) => { posts.value = posts.value.map((p) => (p.id === updated.id ? { ...p, ...updated } : p)); },
);

// ============================================================ MESSAGES
const threads = ref<any[]>([]);
const threadsLoading = ref(false);
const openedThread = ref<any>(null);
const openedMessages = ref<any[]>([]);
const replyBody = ref('');
const replyPhotos = ref<File[]>([]);
const sendingReply = ref(false);
const replyError = ref('');

// "Send later" for a NEW conversation: same zone and horizon, from the threads' meta.
const messageScheduling = ref<{ timezone: string; max_days_ahead: number } | null>(null);
const messageLater = useSendLater(
    computed(() => messageScheduling.value?.timezone),
    computed(() => messageScheduling.value?.max_days_ahead),
);
const scheduledMessages = ref<ScheduledRow[]>([]);

/** True once the conversations have been listed (the Messages tab was opened). */
const threadsLoaded = ref(false);

/**
 * The conversations of this class. `quiet` is a refresh of a list already on screen
 * (regained focus, a conversation just opened): no spinner, the scheduled list is
 * left alone, and a failure keeps the rows that are there. Returns whether the list
 * (and so the class number) is now the server's.
 */
const loadThreads = async (quiet = false): Promise<boolean> => {
    if (!quiet) threadsLoading.value = true;
    try {
        const res = await TeacherApiService.get(`${base.value}/threads`);
        threads.value = rowsOf(res.data?.data);
        threadsLoaded.value = true;
        // The class's whole number, exact even when the list is paginated: trust it
        // over any local subtraction.
        if (group.value && res.data?.meta?.unread_total !== undefined) {
            group.value.unread_messages = unreadNumber(res.data.meta.unread_total);
        }
        messageScheduling.value = res.data?.meta?.scheduling ?? null;
        messageMedia.value = pickerLimits(res.data?.meta, 'max_images_per_message', 'max_videos_per_message');
        if (!quiet) await loadScheduledMessages();
        return true;
    } catch {
        if (!quiet) threads.value = [];
        return false;
    } finally {
        if (!quiet) threadsLoading.value = false;
    }
};

/** The conversations written and waiting, or refused at send time. */
const loadScheduledMessages = async () => {
    try {
        const res = await TeacherApiService.get(`${base.value}/scheduled-messages`);
        scheduledMessages.value = rowsOf(res.data?.data)
            .filter((item: any) => item.status !== 'sent' && item.status !== 'cancelled')
            .map(messageRow);
    } catch {
        scheduledMessages.value = [];
    }
};

const runScheduledMessage = async (action: () => Promise<unknown>, failure: string) => {
    scheduledBusy.value = true;
    scheduledError.value = '';
    try {
        await action();
        await loadScheduledMessages();
    } catch (e: any) {
        scheduledError.value = apiErrorText(e, failure);
    } finally {
        scheduledBusy.value = false;
    }
};

const sendMessageNow = (row: ScheduledRow) =>
    runScheduledMessage(() => TeacherApiService.put(`${base.value}/scheduled-messages/${row.id}`, { send_now: true }), 'That message could not be sent.');

const cancelMessage = (row: ScheduledRow) =>
    runScheduledMessage(() => TeacherApiService.delete(`${base.value}/scheduled-messages/${row.id}`), 'That message could not be cancelled.');

const saveMessage = (row: ScheduledRow, fields: { heading: string; body: string; sendAt: string | null }) =>
    runScheduledMessage(() => TeacherApiService.put(`${base.value}/scheduled-messages/${row.id}`, {
        subject: fields.heading,
        body: fields.body,
        ...(fields.sendAt ? { send_at: fields.sendAt } : {}),
    }), 'That message could not be saved.');

/** Every page of one conversation, oldest first (see openWholeThread). */
const readWholeThread = (threadId: number) => openWholeThread<any, any>(async (page) => {
    const res = await TeacherApiService.get(
        `${base.value}/threads/${threadId}?per_page=${MESSAGE_PAGE_SIZE}&page=${page}`,
    );
    return res.data?.data;
});

/**
 * Read the open conversation again, after a reply. A parent may have written while
 * it was open: the server leaves the reader's bookmark BEHIND such a message (a
 * reply does not mean "I read what arrived meanwhile"), so only a fresh read puts
 * it on screen and clears its count. Without this the tab says "1 new" for a
 * message the teacher cannot see until they close and reopen the conversation.
 * A failed re-read changes nothing: the reply itself is already shown.
 */
const rereadOpenThread = async () => {
    const thread = openedThread.value;
    if (!thread) return;
    try {
        const opened = await readWholeThread(thread.id);
        // The teacher may have opened another conversation while this was in flight.
        if (openedThread.value?.id !== thread.id) return;
        openedThread.value = opened.thread ?? thread;
        openedMessages.value = opened.messages;
    } catch {
        // Keep what is on screen.
    }
};

const openThread = async (thread: any) => {
    try {
        // Every page, oldest first: the server moves the bookmark to the newest message
        // it served, so a conversation longer than one page is only read (and only
        // cleared) once its last page has been fetched.
        const opened = await readWholeThread(thread.id);
        openedThread.value = opened.thread ?? thread;
        replyBody.value = '';
        replyPhotos.value = [];
        replyError.value = '';
        openedMessages.value = opened.messages;
        // Opening it IS reading it, ALL of it: the server cleared whatever had arrived,
        // which can be more than the row said when the list was loaded. So the number
        // comes from a fresh list, not from subtracting the row's old count. Only if
        // that refresh fails is the row's count taken off as a guess.
        const stale = thread.unread_count;
        thread.unread_count = 0;
        thread.unread = false;
        if (!(await loadThreads(true)) && group.value) {
            group.value.unread_messages = afterOpening(group.value.unread_messages, stale);
        }
    } catch {
        replyError.value = 'That conversation could not be opened.';
    }
};

const sendReply = async () => {
    const body = replyBody.value.trim();
    if ((!body && !replyPhotos.value.length) || !openedThread.value) return;
    sendingReply.value = true;
    replyError.value = '';
    try {
        const url = `${base.value}/threads/${openedThread.value.id}/messages`;
        const res = replyPhotos.value.length
            ? await TeacherApiService.postForm(url, withPhotos({ body }, replyPhotos.value))
            : await TeacherApiService.post(url, { body });
        openedMessages.value.push(res.data?.data);
        replyBody.value = '';
        replyPhotos.value = [];
        await rereadOpenThread();
        await loadThreads();
    } catch (e: any) {
        replyError.value = photoErrorText(e, 'Your reply could not be sent.', 'message');
    } finally {
        sendingReply.value = false;
    }
};

// Change the words of a message YOU sent (W7). Throws on a refusal so the editor
// shows the server's sentence in place and keeps the draft; on success the list
// takes the server's answer. No loadThreads(): an edit does not move the
// conversation in the list.
const editMessage = async (m: any, body: string) => {
    if (!openedThread.value) throw new Error('No conversation is open.');
    const res = await TeacherApiService.put(`${base.value}/threads/${openedThread.value.id}/messages/${m.id}`, { body });
    if (res.data?.data) openedMessages.value = replaceMessage(openedMessages.value, res.data.data);
};

// A reaction on a message. Two idempotent verbs, not a toggle, so a double
// tap cannot undo itself; the server answers with the message's fresh counts.
const reactTo = async (m: any, key: string, on: boolean) => {
    if (!openedThread.value) return null;
    const url = `${base.value}/threads/${openedThread.value.id}/messages/${m.id}/reactions/${key}`;
    try {
        const res = on ? await TeacherApiService.put(url) : await TeacherApiService.delete(url);
        return res.data?.data?.reactions ?? null;
    } catch (e: any) {
        replyError.value = apiErrorText(e, 'That reaction could not be saved.');
        return null;
    }
};

// A reaction on a class story post. The same two idempotent verbs as a message
// reaction; the server answers with the post's fresh counts and names.
const reactToPost = async (post: any, key: string, on: boolean) => {
    const url = `${base.value}/posts/${post.id}/reactions/${key}`;
    try {
        const res = on ? await TeacherApiService.put(url) : await TeacherApiService.delete(url);
        return res.data?.data?.reactions ?? null;
    } catch (e: any) {
        postError.value = apiErrorText(e, 'That reaction could not be saved.');
        return null;
    }
};

// ---------- avatar save ----------
// The sheet stays open on the student, now showing the avatar just chosen.
const onAvatarSaved = (student: any) => {
    if (sheetFor.value?.contact && student?.contact) {
        sheetFor.value.contact.avatar = student.contact.avatar ?? null;
    }
};

// ---------- lazy per-tab loading ----------
// ---------- report cards ----------
type ReportType = 'report_card' | 'progress';
type DraftCell = { level: number | null; comment: string };

// The period. All three are ADOPTED from the server on first load rather than
// computed here: which quarter it is, and when the school year rolls over, are
// facts about the school. A copy of them in this component is a copy that goes
// stale in a year — the same reason `levelKey` is read from the payload.
const reportType = ref<ReportType>('report_card');
const reportTerm = ref<number | null>(null);
const reportYear = ref('');
const reportPeriod = ref<any>(null);

const reportRows = ref<any[]>([]);
const reportsLoading = ref(false);
const reportsError = ref('');

const openCard = ref<any>(null);

// `draft` is what is on screen; `baseline` is the server's last known truth.
// Keyed by MARK id, because ids are stable and array order is not.
const draft = ref<Record<number, DraftCell>>({});
const baseline = ref<Record<number, DraftCell>>({});
const teacherComment = ref('');
const teacherCommentBaseline = ref('');

const savingCard = ref(false);
const cardSaved = ref(false);
const publishing = ref(false);
const publishFrom = ref('');
const publishTo = ref('');
const leaveWarned = ref(false);

const isDirty = (id: number): boolean => {
    const a = draft.value[id];
    const b = baseline.value[id];
    return !!a && (!b || a.level !== b.level || a.comment !== b.comment);
};

const dirtyIds = computed(() => Object.keys(draft.value).map(Number).filter(isDirty));
const commentDirty = computed(() => teacherComment.value !== teacherCommentBaseline.value);
const unsavedCount = computed(() => dirtyIds.value.length + (commentDirty.value ? 1 : 0));
const hasUnsaved = computed(() => unsavedCount.value > 0);

// Counted from the DRAFT, so the header moves as the teacher marks rather than
// only after a save.
const cardTotal = computed(() => Object.keys(draft.value).length);
const downloadingCard = ref(false);

/**
 * Fetched as a blob, not linked: this route is bearer-authenticated like the
 * rest of the realm and a plain <a href> carries no Authorization header.
 */
const downloadCard = async () => {
    if (!openCard.value) return;

    downloadingCard.value = true;

    try {
        // The period comes off the OPEN CARD, not off the three period selects.
        // They are the same until a teacher changes a select without reopening,
        // and then they are not — and a PDF that quietly differs from the card
        // on screen is the kind of wrong nobody checks for.
        const c = openCard.value;
        const url = await TeacherApiService.blobUrl(
            `${base.value}/members/${c.student.membership_id}/report-card/pdf`
            + `?type=${encodeURIComponent(c.type)}`
            + `&school_year=${encodeURIComponent(c.school_year)}`
            + `&term=${encodeURIComponent(String(c.term))}`,
        );
        const a = document.createElement('a');
        a.href = url;
        a.download = `${name(c.student?.contact)} - ${c.period_label}.pdf`;
        a.click();
        URL.revokeObjectURL(url);
    } catch {
        reportsError.value = 'That report could not be downloaded just now.';
    } finally {
        downloadingCard.value = false;
    }
};

const cardAssessed = computed(() => Object.values(draft.value).filter((c) => c.level !== null).length);

// Three years around whatever the server said the current one is — derived, so
// nobody has to remember to add next year to a hardcoded list.
const schoolYearOptions = computed<string[]>(() => {
    const current = reportPeriod.value?.school_year ?? reportYear.value;
    const start = parseInt(String(current).slice(0, 4), 10);
    if (!Number.isFinite(start)) return current ? [current] : [];
    return [-1, 0, 1].map((d) => `${start + d}-${start + d + 1}`);
});

// Monotonic, so a slow response for a quarter the teacher has already moved on
// from cannot land and rewrite the period underneath them. Without it the
// adoption below would drag the selects back to whatever finished last.
let reportReq = 0;

// Set while the loader is copying the server's period into the three selects, so
// the watcher does not treat OUR OWN adoption as the teacher changing quarter
// and fire a second identical fetch.
let adoptingPeriod = false;

const reportQuery = (): string => {
    const q = new URLSearchParams({ type: reportType.value });
    if (reportTerm.value) q.set('term', String(reportTerm.value));
    if (reportYear.value) q.set('school_year', reportYear.value);
    return q.toString();
};

const loadReportCards = async () => {
    const mine = ++reportReq;
    reportsLoading.value = true;
    reportsError.value = '';
    try {
        const res = await TeacherApiService.get(`${base.value}/report-cards?${reportQuery()}`);
        if (mine !== reportReq) return;   // a newer request has already answered

        reportRows.value = res.data?.data?.students ?? [];
        reportPeriod.value = res.data?.data?.period ?? null;
        levelKey.value = res.data?.performance_levels ?? levelKey.value;
        // Adopt what the server actually used. It clamps a bad term or year
        // silently, so without this the selects could claim one quarter while
        // every save landed in another.
        if (reportPeriod.value) {
            adoptingPeriod = true;
            reportType.value = reportPeriod.value.type;
            reportTerm.value = reportPeriod.value.term;
            reportYear.value = reportPeriod.value.school_year;
            await nextTick();
            adoptingPeriod = false;
        }
    } catch {
        if (mine === reportReq) reportsError.value = 'Could not load the report cards.';
    } finally {
        if (mine === reportReq) reportsLoading.value = false;
    }
};

/**
 * Seed the draft from a card. NEVER invents a level: an unmarked criterion
 * stays null, for the same reason the register does not seed a default mark.
 *
 * `preserve` keeps whatever the teacher has already typed while still taking
 * the server's answer as the new baseline — used when a save is refused, so the
 * form can lock itself without throwing their work away.
 */
const hydrateCardDraft = (card: any, preserve = false) => {
    const nextDraft: Record<number, DraftCell> = preserve ? { ...draft.value } : {};
    const nextBaseline: Record<number, DraftCell> = {};

    const take = (m: any) => {
        const server: DraftCell = { level: m.level ?? null, comment: m.comment ?? '' };
        nextBaseline[m.id] = { ...server };
        if (!preserve || !(m.id in nextDraft)) nextDraft[m.id] = { ...server };
    };

    for (const sub of card?.subjects ?? []) for (const m of sub.criteria ?? []) take(m);
    for (const m of card?.learning_behaviours ?? []) take(m);

    draft.value = nextDraft;
    baseline.value = nextBaseline;
    teacherCommentBaseline.value = card?.teacher_comment ?? '';
    if (!preserve) teacherComment.value = card?.teacher_comment ?? '';
};

const openReportCard = async (row: any) => {
    reportsError.value = '';
    cardSaved.value = false;
    leaveWarned.value = false;
    publishFrom.value = '';
    publishTo.value = '';
    try {
        const res = await TeacherApiService.get(
            `${base.value}/members/${row.membership_id}/report-card?${reportQuery()}`
        );
        openCard.value = res.data?.data ?? null;
        levelKey.value = res.data?.performance_levels ?? levelKey.value;
        hydrateCardDraft(openCard.value);
    } catch {
        reportsError.value = 'Could not open that report card.';
    }
};

const onCardEdited = () => { cardSaved.value = false; };

/** Tapping the level a child already has CLEARS it — a mis-tap must be one tap to undo. */
const setMarkLevel = (id: number, level: number) => {
    const cell = draft.value[id];
    if (!cell) return;
    cell.level = cell.level === level ? null : level;
    onCardEdited();
};

const clearMarkLevel = (id: number) => {
    const cell = draft.value[id];
    if (!cell) return;
    cell.level = null;
    onCardEdited();
};

/** 4/3/2/1 set a level; 0, Backspace and Delete clear it. Anything else falls through. */
const onLevelKey = (e: KeyboardEvent, id: number) => {
    if (['4', '3', '2', '1'].includes(e.key)) {
        e.preventDefault();
        setMarkLevel(id, Number(e.key));
    } else if (e.key === '0' || e.key === 'Backspace' || e.key === 'Delete') {
        e.preventDefault();
        clearMarkLevel(id);
    }
};

const reportErrorFrom = (e: any): string =>
    e?.response?.data?.data?.marks?.[0]
    ?? e?.response?.data?.data?.['marks.0.level']?.[0]
    ?? e?.response?.data?.data?.teacher_comment?.[0]
    ?? 'Those marks could not be saved.';

const saveReportCard = async (): Promise<boolean> => {
    if (!hasUnsaved.value) return true;

    savingCard.value = true;
    reportsError.value = '';
    try {
        // Only the rows that MOVED, and each one whole: sending {id, level}
        // alone would wipe that row's stored comment.
        const marks = dirtyIds.value.map((id) => ({
            id,
            level: draft.value[id].level,
            comment: draft.value[id].comment.trim() || null,
        }));

        const body: any = { marks };
        // Sent only when it changed, and as a string so '' can clear it —
        // null leaves the stored comment untouched.
        if (commentDirty.value) body.teacher_comment = teacherComment.value;

        const res = await TeacherApiService.put(
            `${base.value}/members/${openCard.value.student.membership_id}/report-card?${reportQuery()}`,
            body
        );

        openCard.value = res.data?.data ?? openCard.value;
        // preserve, NOT a clean re-hydrate. The inputs stay live during a save
        // (a teacher on a classroom phone keeps marking while the PUT is in
        // flight), and a clean hydrate would overwrite anything they touched in
        // that window with the server's older value and then show a green
        // "Saved" over the top of it. With preserve the server becomes the new
        // baseline, rows nobody touched come out clean, and rows edited mid-save
        // stay correctly counted as unsaved.
        hydrateCardDraft(openCard.value, true);
        // Only claim success if the screen now matches the server. If they kept
        // marking, "Saved" would be a lie about the marks still on screen.
        cardSaved.value = !hasUnsaved.value;
        await loadReportCards();
        return true;
    } catch (e: any) {
        reportsError.value = reportErrorFrom(e);
        // A refusal means the card was published underneath us. Re-read so the
        // form locks to the truth, keeping what the teacher had typed.
        if (e?.response?.status === 422) await reloadOpenCard(true);
        return false;
    } finally {
        savingCard.value = false;
    }
};

const reloadOpenCard = async (preserve: boolean) => {
    try {
        const res = await TeacherApiService.get(
            `${base.value}/members/${openCard.value.student.membership_id}/report-card?${reportQuery()}`
        );
        openCard.value = res.data?.data ?? openCard.value;
        hydrateCardDraft(openCard.value, preserve);
    } catch { /* the message from the failed save is the useful one */ }
};

const publishReportCard = async () => {
    if (hasUnsaved.value || !cardAssessed.value) return;
    publishing.value = true;
    reportsError.value = '';
    try {
        const q = new URLSearchParams(reportQuery());
        if (publishFrom.value) q.set('from', publishFrom.value);
        if (publishTo.value) q.set('to', publishTo.value);

        const res = await TeacherApiService.post(
            `${base.value}/members/${openCard.value.student.membership_id}/report-card/publish?${q.toString()}`
        );
        // Deliberately does NOT touch levelKey — the write carries no
        // performance_levels, and reading it here would blank the key.
        openCard.value = res.data?.data ?? openCard.value;
        hydrateCardDraft(openCard.value);
        await loadReportCards();
    } catch {
        reportsError.value = 'That report could not be sent.';
    } finally {
        publishing.value = false;
    }
};

const unpublishReportCard = async () => {
    publishing.value = true;
    reportsError.value = '';
    try {
        const keep = hasUnsaved.value;
        const res = await TeacherApiService.delete(
            `${base.value}/members/${openCard.value.student.membership_id}/report-card/publish?${reportQuery()}`
        );
        openCard.value = res.data?.data ?? openCard.value;
        // Keep the draft when there is one. "Take it back" is the ONLY exit the
        // screen offers after a save is refused, and that path deliberately kept
        // the teacher's marks — hydrating clean here would destroy exactly the
        // work it preserved, at the moment they followed our own instruction.
        hydrateCardDraft(openCard.value, keep);
        await loadReportCards();
    } catch {
        reportsError.value = 'That report could not be taken back.';
    } finally {
        publishing.value = false;
    }
};

const closeOpenCard = () => {
    if (hasUnsaved.value) { leaveWarned.value = true; return; }
    openCard.value = null;
    leaveWarned.value = false;
};

const saveAndClose = async () => {
    if (await saveReportCard()) { openCard.value = null; leaveWarned.value = false; }
};

const discardAndClose = () => {
    openCard.value = null;
    leaveWarned.value = false;
    draft.value = {};
    baseline.value = {};
    // The comment is half of `hasUnsaved`, and forgetting it left the whole tab
    // wedged: with no card open and nothing on screen to show it, `hasUnsaved`
    // stayed true forever, the period watcher then refused every change while
    // the <select> still moved, and clicking a student from a Quarter 2 list
    // opened — and saved into — Quarter 3.
    teacherComment.value = '';
    teacherCommentBaseline.value = '';
};

/**
 * A leaving date is a calendar DAY, not an instant: 'YYYY-MM-DD' through
 * `new Date()` is UTC midnight, which renders as the day BEFORE for every reader
 * west of UTC. Read the stored day literally instead.
 */
const leftDay = (iso: string | null): string => {
    if (!iso) return '';
    const [y, m, d] = iso.slice(0, 10).split('-').map(Number);
    if (!y || !m || !d) return iso;
    return new Date(y, m - 1, d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
};

/** Never "0 of 0": `criteria` counts the learning behaviours too, so the fraction is honest. */
const rowStatus = (row: any): { text: string; cls: string } => {
    if (!row.started) return { text: 'Not started', cls: 'bg-light text-muted' };
    if (row.published) return { text: `Sent ${when(row.published_at)}`, cls: 'bg-success-subtle text-success-emphasis' };
    return {
        text: `${row.assessed} of ${row.criteria} marked`,
        cls: row.assessed >= row.criteria ? 'bg-success-subtle text-success-emphasis' : 'bg-light text-muted',
    };
};

// A different (type, year, term) is a DIFFERENT document, so the open card must
// not survive the change. Unsaved work stops the switch rather than being lost.
/** Put the selects back to the period actually loaded, without re-entering the watcher. */
const restorePeriod = async () => {
    if (!reportPeriod.value) return;
    adoptingPeriod = true;
    reportType.value = reportPeriod.value.type;
    reportTerm.value = reportPeriod.value.term;
    reportYear.value = reportPeriod.value.school_year;
    await nextTick();
    adoptingPeriod = false;
};

watch([reportType, reportTerm, reportYear], () => {
    // Our own adoption of the server's period, not the teacher choosing one.
    if (adoptingPeriod) return;

    // Unsaved work blocks the switch — but ONLY while a card is open, where the
    // warning is actually visible. Blocking it with no card open was a silent
    // wedge: the <select> moved, nothing said why the list did not, and the next
    // student opened a different quarter's document.
    if (openCard.value && hasUnsaved.value) { leaveWarned.value = true; restorePeriod(); return; }

    openCard.value = null;
    loadReportCards();
});


watch(activeTab, (tab) => {
    if (tab === 'story' && !posts.value.length && !postsLoading.value) loadPosts();
    if (tab === 'messages' && !threads.value.length && !threadsLoading.value) loadThreads();
    if (tab === 'points' && !skills.value.length) loadSkills();
    if (tab === 'points') {
        if (group.value?.class_subjects_enabled === true) loadPointsTotals(weekFromQuery(route.query.week));
        else loadPointsTotals();
    }
    if (tab === 'attendance') loadAttendance();
    if (tab === 'hifz') loadSurahs();
    // Every time, not once: the counts move whenever a child is marked, and this
    // is the screen the teacher comes back to between children.
    if (tab === 'letters') loadLettersOverview();
    // Back to the tab: refresh the list but keep the plan being written — the
    // form's own "Message their family" link leaves the tab mid-draft.
    if (tab === 'lessons') { loadLessonPlans(!lessonsLoaded); if (!curriculum.value.grades.length) loadCurriculum(); }
    // The "Add from this class's files" list under Activities (T-004.1).
    if (tab === 'lessons' && !resources.value.length) loadResources();
    if (tab === 'grades' && !assignments.value.length) loadAssignments();
    if (tab === 'files' && !resources.value.length) loadResources();
    if (tab === 'reports' && !reportRows.value.length && !reportsLoading.value) loadReportCards();
    if (tab !== 'grades') { openAssignment.value = null; }
    // Unlike the gradebook above, an open report card is nulled only when there
    // is nothing unsaved: a half-marked card is not stale state, it is the
    // teacher's afternoon, and one stray tap on "More" would otherwise bin it.
    if (tab !== 'reports' && !hasUnsaved.value) { openCard.value = null; leaveWarned.value = false; }
    // Reset any open per-student detail when leaving a grading tab.
    if (tab !== 'letters') { selected.value = null; }
    if (tab !== 'messages') { openedThread.value = null; }
});

const classSubjects = useClassSubjects({
    realm: 'teacher', group, base, activeTab, api: TeacherApiService,
    activate: (tab, alphabet) => {
        if (alphabet && lettersAlphabet.value !== alphabet) {
            selected.value = null;
            tracker.value = null;
            lettersAlphabet.value = alphabet;
        }
        activeTab.value = tab as TabKey;
    },
});
// An ON address can choose another week without changing the Points line.
watch(() => [route.query.tab, route.query.week], ([tab, week], [previousTab]) => {
    if (classSubjects.enabled.value && tab === 'points' && previousTab === 'points') {
        loadPointsTotals(weekFromQuery(week));
    }
});
</script>

<style scoped>
.nav-tabs .nav-link { color: #6c757d; }
.nav-tabs .nav-link.active { color: #198754; font-weight: 600; }
/* The "N new" chip beside the class name: a real button, so it needs a pointer and a focus ring. */
.tc-new-chip { cursor: pointer; font-size: .75rem; padding: .4em .7em; }
.tc-new-chip:focus-visible { outline: 2px solid #0d6efd; outline-offset: 2px; }
/* A Roster row is one button: tall enough for a thumb on any screen, not only a phone. */
.tc-roster-row { min-height: 56px; }

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

/* Above the accordion cards below it, and scrolls rather than running off a phone. */
.tc-std-list { top: 100%; left: 0; z-index: 30; max-height: 18rem; overflow-y: auto; margin-top: 2px; }
.tc-std-list .list-group-item { cursor: pointer; }
.tc-std-meta { font-size: .75rem; }
.tc-std-divider { font-size: .65rem; letter-spacing: .04em; background: var(--bs-tertiary-bg); cursor: default; }
</style>

<!-- The phone layout, in its own file so it can change without touching the
     template above. Scoped like the block before it. -->
<style scoped src="./TeacherClass.phone.css"></style>
