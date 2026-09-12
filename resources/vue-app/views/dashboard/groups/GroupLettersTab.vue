<template>
    <div>
        <!-- WHICH ALPHABET. Two tracks, never one grid: a child's A–Z has its
             own drills, its own denominator and its own reading direction, and
             merging them would draw a fifty-four letter alphabet no class is
             teaching. Outside the loading branch so the control a teacher just
             pressed does not vanish while its answer is on the way. -->
        <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Alphabet">
            <button v-for="a in ALPHABETS" :key="a.id" type="button"
                    class="btn" :class="alphabet === a.id ? 'btn-success' : 'btn-outline-success'"
                    :disabled="loading" :aria-pressed="alphabet === a.id"
                    @click="switchAlphabet(a.id)">
                {{ a.label }}
            </button>
        </div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border text-success"></span></div>

        <template v-else>
            <!-- What the ROOM is working on. The stage belongs to the class, not
                 to thirty children who would each carry a number that has to
                 agree with where they sit. -->
            <div class="card border-0 bg-light mb-3">
                <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
                    <div>
                        <div class="fw-semibold">{{ overview?.stage?.label }}</div>
                        <div class="text-muted small">{{ overview?.stage?.summary }}</div>
                    </div>
                    <!-- The ladder is offered only where there IS one. English
                         has a single stage — a class working through A–Z is
                         working through A–Z — so the endpoint answers with one
                         stage and refuses to be told a different one. Read off
                         the payload rather than branched on the alphabet id, so
                         a third track decides this for itself. -->
                    <div v-if="(overview?.stages?.length ?? 0) > 1" class="d-flex align-items-center gap-2">
                        <label class="small text-muted mb-0">This class is on</label>
                        <select class="form-select form-select-sm" style="width:auto"
                                :value="overview?.stage?.id" :disabled="savingStage"
                                @change="setStage(($event.target as HTMLSelectElement).value)">
                            <option v-for="s in overview?.stages" :key="s.id" :value="s.id">{{ s.label }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- The same slot carries the confirmation and the refusal, because
                 they answer the same question ("did that take?"), but a refusal
                 styled as information is a refusal a reader skims past. -->
            <div v-if="stageNote" class="py-2 small alert"
                 :class="stageFailed ? 'alert-warning' : 'alert-info'">{{ stageNote }}</div>

            <!-- The class at a glance -->
            <div v-if="!selected" class="list-group">
                <button v-for="s in overview?.students" :key="s.membership_id" type="button"
                        class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                        @click="open(s)">
                    <PersonAvatar :avatar="s.contact?.avatar"
                                  :first-name="s.contact?.first_name" :last-name="s.contact?.last_name"
                                  :size="38" />
                    <div class="flex-grow-1">
                        <div class="fw-semibold small">{{ name(s.contact) }}</div>
                        <div class="progress mt-1" style="height:6px;">
                            <div class="progress-bar bg-success"
                                 :style="{ width: Math.round((s.completion || 0) * 100) + '%' }"></div>
                        </div>
                    </div>
                    <span class="text-muted small text-nowrap">{{ s.mastered }} / {{ overview?.total }}</span>
                </button>
                <div v-if="!overview?.students?.length" class="text-muted small p-3">
                    No students on this roster yet.
                </div>
            </div>

            <!-- One child's tracker -->
            <div v-else>
                <button class="btn btn-link px-0 text-decoration-none mb-2" @click="selected = null">
                    &larr; All students
                </button>

                <div class="d-flex align-items-center gap-3 mb-3">
                    <PersonAvatar :avatar="tracker?.student?.contact?.avatar"
                                  :first-name="tracker?.student?.contact?.first_name"
                                  :last-name="tracker?.student?.contact?.last_name" :size="48" />
                    <div>
                        <div class="fw-semibold">{{ name(tracker?.student?.contact) }}</div>
                        <div class="text-muted small">
                            {{ tracker?.totals?.mastered }} of {{ tracker?.totals?.total }} mastered
                        </div>
                    </div>
                </div>

                <!-- The alphabet's OWN direction, off the payload. Arabic starts
                     at the top right and runs leftward; laid out the other way
                     it reads as a foreign list of symbols rather than as the
                     alphabet a child is learning. English is the mirror of that
                     argument, which is why the direction cannot stay a constant
                     in the template — the server says which way its alphabet
                     goes, and every row below asks it. -->
                <div class="d-flex flex-wrap gap-2 mb-3" :dir="dir">
                    <button v-for="l in tracker?.letters" :key="l.id" type="button"
                            class="letter-tile" :class="`letter-tile--${l.status}`"
                            @click="openLetter = openLetter === l.id ? null : l.id">
                        <span class="letter-tile__glyph">{{ l.glyph }}</span>
                        <span class="letter-tile__name">{{ l.transliteration }}</span>
                    </button>
                </div>

                <div v-if="letter" class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-baseline mb-2">
                            <h6 class="mb-0">{{ letterHeading }}</h6>
                            <button class="btn-close" @click="openLetter = null"></button>
                        </div>

                        <!-- Joining is a fact about ARABIC letters. Every English
                             letter arrives with connects_forward false because
                             nothing joins in English, and printing the sentence
                             below for all twenty-six would state a rule of the
                             qāʿidah about the Latin alphabet. -->
                        <p v-if="isArabicTrack && !letter.connects_forward" class="text-muted small">
                            This letter never joins to the one after it, so it has two shapes.
                        </p>
                        <!-- Same direction, same reason: an Arabic word BEGINS at
                             the right, so the initial form belongs on the right
                             of this row — and an English word does not. -->
                        <div class="d-flex gap-2 mb-3" :dir="dir">
                            <div v-for="p in letter.positions" :key="p.id" class="shape-box">
                                <div class="shape-box__glyph">{{ p.text }}</div>
                                <div class="shape-box__label">{{ positionLabel(p.id) }}</div>
                            </div>
                        </div>

                        <div class="list-group" :dir="dir">
                            <button v-for="d in letter.drills" :key="d.id" type="button"
                                    class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                                    :class="`drill--${d.status}`"
                                    :disabled="marking === d.id"
                                    @click="advance(d)">
                                <span class="drill__glyph">{{ d.text }}</span>
                                <span class="flex-grow-1 small" dir="ltr" style="text-align:start;">
                                    {{ d.label }}
                                    <span v-if="d.sound" class="text-muted">· sounds like “{{ d.sound }}”</span>
                                </span>
                                <span class="badge" :class="badgeClass(d.status)">{{ statusLabel(d.status) }}</span>
                            </button>
                        </div>
                        <!-- A refused mark has to SAY SO. This catch was silent
                             here while the teacher's copy of the same screen
                             already surfaced it: the tile stays where it was,
                             which is right, but with nothing on screen a 422 is
                             indistinguishable from a dead button and an office
                             admin taps the same letter until they give up. -->
                        <p v-if="letterError" class="text-danger small mt-2 mb-0">{{ letterError }}</p>
                        <p class="text-muted small mt-2 mb-0">
                            Tap to move: Not started → Learning → Mastered.
                        </p>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import PersonAvatar from '@/components/common/PersonAvatar.vue';
import ApiService from '@/core/services/ApiService';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ groupId: number; masjidId: number }>();

const loading = ref(true);
const savingStage = ref(false);
const marking = ref<string | null>(null);
const overview = ref<any>(null);
const tracker = ref<any>(null);
const selected = ref<any>(null);
const openLetter = ref<string | null>(null);
const stageNote = ref('');
const stageFailed = ref(false);
const letterError = ref('');

/**
 * The tracks this tab can show, in the order the server offers them.
 *
 * Held here rather than read off a payload because the switcher has to be
 * drawn BEFORE the first response arrives, and a control that appears late is
 * a control a teacher scrolls past. The ids are the server's allowlist
 * (`App\Support\Letters\CurriculumRegistry::ALPHABETS`); anything else is a 422
 * rather than a quiet fallback to Arabic.
 */
const ALPHABETS = [
    { id: 'arabic', label: 'Arabic' },
    { id: 'english', label: 'English' },
] as const;

/**
 * NOT remembered, on purpose.
 *
 * A stored choice would greet the next person to open this tab — usually a
 * different member of office staff on a shared machine — with a grid of Latin
 * letters where the qāʿidah used to be, and the tab's own heading would not
 * explain why. Defaulting to Arabic every time also matches the endpoints,
 * which read the qāʿidah when no `?alphabet=` is sent, so the screen and the
 * API agree about what "no choice made" means.
 */
const alphabet = ref<string>('arabic');

const base = computed(() => `/api/admin/masjids/${props.masjidId}/groups/${props.groupId}`);
const letter = computed(() => tracker.value?.letters?.find((l: any) => l.id === openLetter.value) ?? null);

/**
 * Which way this alphabet is laid out, as the payload declares it.
 *
 * The tracker answers for the letters currently on screen; the overview is the
 * fallback for the moment between switching and the tracker coming back, and
 * `rtl` is the last resort so a payload from an older deploy renders the way it
 * always did.
 */
const dir = computed(() => tracker.value?.direction ?? overview.value?.direction ?? 'rtl');

/** Whether the letters on screen are the qāʿidah's, for the notes that only make sense there. */
const isArabicTrack = computed(() => (tracker.value?.alphabet ?? alphabet.value) === 'arabic');

/**
 * The open letter's heading.
 *
 * `arabic_name — transliteration` was safe while there was one alphabet. An
 * English letter carries neither an Arabic name nor a transliteration of one,
 * so the same template printed a leading em dash in front of a blank. Both
 * halves are optional now and the dash appears only when there are two things
 * to separate.
 */
const letterHeading = computed(() => {
    const l = letter.value;
    if (!l) return '';

    return [l.arabic_name, l.transliteration ?? l.glyph].filter(Boolean).join(' — ');
});

const name = (c: any) => [c?.first_name, c?.last_name].filter(Boolean).join(' ') || 'Student';

// Arabic's four contextual forms and English's two cases. One map: the shape
// row is the same component either way, and an unknown id still renders as
// itself rather than as nothing.
const POSITIONS: Record<string, string> = {
    isolated: 'Alone', initial: 'Beginning', medial: 'Middle', final: 'End',
    upper: 'Capital', lower: 'Small',
};
const positionLabel = (id: string) => POSITIONS[id] ?? id;

const STATUS: Record<string, string> = {
    not_started: 'Not started', learning: 'Learning', mastered: 'Mastered',
};
const statusLabel = (s: string) => STATUS[s] ?? s;
const badgeClass = (s: string) => s === 'mastered'
    ? 'bg-success-subtle text-success-emphasis'
    : (s === 'learning' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light text-muted');

const NEXT: Record<string, string> = {
    not_started: 'learning', learning: 'mastered', mastered: 'not_started',
};

/**
 * The class grid for one track.
 *
 * The alphabet is a PARAMETER rather than a read of `alphabet.value`, so a
 * switch can fetch the new track before committing to it — see switchAlphabet().
 * Failures are the caller's: at mount there is nothing to fall back to, and on a
 * switch the whole point is that the previous track stays.
 */
const loadOverview = async (which: string = alphabet.value) => {
    const res = await ApiService.get(`${base.value}/letters?alphabet=${which}` as any);
    overview.value = res.data?.data ?? null;
};

onMounted(async () => {
    try {
        await loadOverview();
    } catch (e: any) {
        // Without this the rejection escaped the component entirely and the tab
        // sat on an empty stage card with nothing said. The roster below is
        // empty for the same reason, so the note is the only thing on screen
        // that can explain it.
        stageFailed.value = true;
        stageNote.value = e?.response?.data?.message
            ?? 'The letters for this class could not be loaded. Reload the page to try again.';
    } finally {
        loading.value = false;
    }
});

/**
 * One child's tracker.
 *
 * Reports its own failure rather than throwing, because it is called from three
 * places — a click in the roster, a track switch and a stage change — and a bare
 * `@click="open(s)"` has nowhere to put a rejection. A failed read drops back to
 * the roster: a child shown as open with the previous child's letters, or the
 * previous TRACK's letters, under their name is worse than not opening at all.
 */
const open = async (student: any, which: string = alphabet.value) => {
    selected.value = student;
    openLetter.value = null;
    letterError.value = '';

    try {
        const res = await ApiService.get(
            `${base.value}/members/${student.membership_id}/letters?alphabet=${which}` as any
        );
        tracker.value = res.data?.data ?? null;
    } catch (e: any) {
        selected.value = null;
        tracker.value = null;
        stageFailed.value = true;
        stageNote.value = e?.response?.data?.message
            ?? "That student's letters could not be loaded. Check your connection and tap again.";
    }
};

/**
 * Change track: re-read, never merge.
 *
 * The whole tab is re-fetched rather than filtered client-side, because the two
 * tracks share nothing a client could recombine — different letters, different
 * drills, a different denominator and a different direction. The open child is
 * re-read for the same reason a stage change re-reads them: what is on screen
 * has to be the answer to the question now being asked.
 *
 * THE HIGHLIGHT MOVES LAST. `alphabet` used to be flipped first and the read
 * left to catch up with no catch at all, so a failed GET (a blip, a 403) left
 * the English button rendered `btn-success` above an Arabic stage card, Arabic
 * per-student totals and an Arabic denominator — and the next mark posted
 * `alphabet: 'english'` against drill ids read off the Arabic grid and 422'd.
 * Fetching against `next` and committing only once the payload is in hand means
 * there is no window in which the button and the grid disagree, and nothing to
 * roll back when the read fails.
 */
const switchAlphabet = async (next: string) => {
    if (next === alphabet.value) return;

    openLetter.value = null;
    letterError.value = '';
    stageNote.value = '';
    stageFailed.value = false;
    loading.value = true;

    try {
        await loadOverview(next);

        // Committed: the payload under the highlight is the one it names.
        alphabet.value = next;
        tracker.value = null;

        // open() reports its own failure and drops back to the roster, so a
        // child whose new track will not load cannot reach the catch below and
        // undo a switch the class view has already made.
        if (selected.value) await open(selected.value, next);
    } catch (e: any) {
        // Only loadOverview() can land here, and nothing has moved: the tab is
        // still showing the track it was showing, which is what the highlight
        // still says. All that is missing is the reason.
        stageFailed.value = true;
        stageNote.value = e?.response?.data?.message
            ?? 'That alphabet could not be loaded. Check your connection and tap again.';
    } finally {
        loading.value = false;
    }
};

/**
 * The stage is the QĀʿIDAH's, so this endpoint is never handed an alphabet: it
 * refuses `english` outright, since `groups.arabic_stage` is the Arabic ladder
 * and writing to it from anywhere else would move a class's Arabic denominator
 * without saying so. The selector that calls this is hidden on a single-stage
 * track for the same reason.
 */
const setStage = async (stage: string) => {
    savingStage.value = true;
    stageNote.value = '';
    stageFailed.value = false;
    try {
        const res = await ApiService.put(`${base.value}/letters/stage` as any, { stage });
        overview.value = res.data?.data ?? overview.value;
        stageNote.value = res.data?.message ?? '';
        // A narrower stage hides later work rather than deleting it, so a child
        // already open must be re-read against the new scope.
        if (selected.value) await open(selected.value);
    } catch (e: any) {
        // The select snaps back to the stage the payload still holds, and this
        // says why rather than leaving a refusal looking like a slow save.
        stageFailed.value = true;
        stageNote.value = e?.response?.data?.message
            ?? 'The class stage could not be changed.';
    } finally {
        savingStage.value = false;
    }
};

/** Marking returns the whole tracker, so the totals and the tile colour move
 *  together rather than the client guessing what the server did. The alphabet
 *  travels with the mark because a drill id alone cannot be placed: `ba` is a
 *  drill on one track and nothing at all on the other, and the server judges it
 *  against the named one. */
const advance = async (drill: any) => {
    marking.value = drill.id;
    letterError.value = '';
    try {
        const res = await ApiService.put(
            `${base.value}/members/${selected.value.membership_id}/letters` as any,
            { drill_id: drill.id, status: NEXT[drill.status] ?? 'learning', alphabet: alphabet.value }
        );
        tracker.value = res.data?.data ?? tracker.value;
        await loadOverview();
    } catch (e: any) {
        // The tile deliberately stays where it was — a failed write must never
        // lie about progress — but it has to SAY SO. This catch did not exist,
        // which is the defect already found and fixed on the teacher's copy of
        // this screen: a 422 ("that drill is not part of what this class is
        // working on") swallowed here reads as a button that does nothing.
        letterError.value = e?.response?.data?.message
            ?? 'That did not save. Check your connection and tap again.';
    } finally {
        marking.value = null;
    }
};
</script>

<style scoped>
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
