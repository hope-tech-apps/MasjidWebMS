<template>
    <div>
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
                    <div class="d-flex align-items-center gap-2">
                        <label class="small text-muted mb-0">This class is on</label>
                        <select class="form-select form-select-sm" style="width:auto"
                                :value="overview?.stage?.id" :disabled="savingStage"
                                @change="setStage(($event.target as HTMLSelectElement).value)">
                            <option v-for="s in overview?.stages" :key="s.id" :value="s.id">{{ s.label }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div v-if="stageNote" class="alert alert-info py-2 small">{{ stageNote }}</div>

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

                <!-- RTL: the alphabet starts at the top RIGHT and runs leftward.
                     Laid out left-to-right it reads as a foreign list of symbols
                     rather than as the alphabet a child is learning to read. -->
                <div class="d-flex flex-wrap gap-2 mb-3" dir="rtl">
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
                            <h6 class="mb-0">{{ letter.arabic_name }} — {{ letter.transliteration }}</h6>
                            <button class="btn-close" @click="openLetter = null"></button>
                        </div>

                        <p v-if="!letter.connects_forward" class="text-muted small">
                            This letter never joins to the one after it, so it has two shapes.
                        </p>
                        <!-- Also RTL: a word BEGINS at the right, so the
                             initial form belongs on the right of this row. -->
                        <div class="d-flex gap-2 mb-3" dir="rtl">
                            <div v-for="p in letter.positions" :key="p.id" class="shape-box">
                                <div class="shape-box__glyph">{{ p.text }}</div>
                                <div class="shape-box__label">{{ positionLabel(p.id) }}</div>
                            </div>
                        </div>

                        <div class="list-group" dir="rtl">
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

const base = computed(() => `/api/admin/masjids/${props.masjidId}/groups/${props.groupId}`);
const letter = computed(() => tracker.value?.letters?.find((l: any) => l.id === openLetter.value) ?? null);

const name = (c: any) => [c?.first_name, c?.last_name].filter(Boolean).join(' ') || 'Student';

const POSITIONS: Record<string, string> = {
    isolated: 'Alone', initial: 'Beginning', medial: 'Middle', final: 'End',
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

const loadOverview = async () => {
    const res = await ApiService.get(`${base.value}/letters` as any);
    overview.value = res.data?.data ?? null;
};

onMounted(async () => {
    try {
        await loadOverview();
    } finally {
        loading.value = false;
    }
});

const open = async (student: any) => {
    selected.value = student;
    openLetter.value = null;
    const res = await ApiService.get(`${base.value}/members/${student.membership_id}/letters` as any);
    tracker.value = res.data?.data ?? null;
};

const setStage = async (stage: string) => {
    savingStage.value = true;
    stageNote.value = '';
    try {
        const res = await ApiService.put(`${base.value}/letters/stage` as any, { stage });
        overview.value = res.data?.data ?? overview.value;
        stageNote.value = res.data?.message ?? '';
        // A narrower stage hides later work rather than deleting it, so a child
        // already open must be re-read against the new scope.
        if (selected.value) await open(selected.value);
    } finally {
        savingStage.value = false;
    }
};

/** Marking returns the whole tracker, so the totals and the tile colour move
 *  together rather than the client guessing what the server did. */
const advance = async (drill: any) => {
    marking.value = drill.id;
    try {
        const res = await ApiService.put(
            `${base.value}/members/${selected.value.membership_id}/letters` as any,
            { drill_id: drill.id, status: NEXT[drill.status] ?? 'learning' }
        );
        tracker.value = res.data?.data ?? tracker.value;
        await loadOverview();
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
