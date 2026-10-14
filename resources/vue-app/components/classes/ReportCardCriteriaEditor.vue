<template>
    <section class="border rounded p-3 mt-3" aria-label="Report-card criteria">
        <h2 class="h5">Report-card criteria</h2>
        <p class="small text-muted">Each new card uses the criteria for its reporting period. Existing cards keep their saved rows.</p>
        <p v-if="error" class="text-danger small" role="alert">{{ error }}</p>
        <p v-if="!settings && !error" class="small">Loading criteria…</p>
        <template v-if="settings">
            <p v-if="settings.draft_effective_on" class="small" role="status">A saved draft applies from {{ settings.draft_effective_on_label }}. {{ nextTerm ? 'You are editing that draft.' : 'You are editing the current criteria.' }}</p>
            <button v-if="settings.draft_bands && nextTerm" type="button" class="btn btn-outline-secondary mb-3" :disabled="saving" @click="editCurrent">Edit current criteria</button>
            <p v-if="nextTerm" class="small">Changes apply from next term. Current cards and criteria stay as saved.</p>
            <button v-if="!nextTerm" type="button" class="btn btn-outline-secondary mb-3" :disabled="saving" @click="startDraft">Changes apply from next term</button>
            <fieldset v-for="(band, b) in bands" :key="band.id ?? `new-${b}`" class="border rounded p-3 mb-3" :disabled="saving">
                <legend class="float-none w-auto px-1 h6">{{ band.name }}</legend>
                <p v-if="band.frozen" class="small">A card using this band has been sent, so its criteria are read-only for this period.</p>
                <div v-if="settings.can_manage_bands" class="d-flex gap-2 flex-wrap mb-3">
                    <label>Band name <input v-model="band.name" :aria-label="`Band name ${b + 1}`" class="form-control" maxlength="120" :disabled="locked(band)"></label>
                    <label>From grade <select v-model="band.from_grade" class="form-select" :aria-label="`From grade ${b + 1}`" :disabled="locked(band)"><option v-for="grade in settings.grades" :key="grade">{{ grade }}</option></select></label>
                    <label>To grade <select v-model="band.to_grade" class="form-select" :aria-label="`To grade ${b + 1}`" :disabled="locked(band)"><option v-for="grade in settings.grades" :key="grade">{{ grade }}</option></select></label>
                    <label class="align-self-end"><input type="radio" name="criteria-default-band" :checked="band.is_default" :disabled="locked(band)" @change="setDefault(b)"> Default for unplaced grades</label>
                    <button type="button" class="btn btn-outline-secondary align-self-end" :disabled="locked(band) || band.from_grade === band.to_grade || bands.length >= 14" @click="splitBand(b)">Split band</button>
                    <button type="button" class="btn btn-outline-secondary align-self-end" :disabled="locked(band) || bands.length === 1" @click="removeBand(b)">Remove band</button>
                </div>
                <p v-else class="small">{{ band.from_grade }} to {{ band.to_grade }}<span v-if="band.is_default"> · Default for unplaced grades</span></p>
                <div v-for="(subject, s) in band.subjects" :key="s" class="border-top pt-2 mb-3">
                    <div class="d-flex gap-2 align-items-end flex-wrap mb-2">
                        <label>Subject <select v-model="subject.name" class="form-select" :aria-label="`Subject ${s + 1} in ${band.name}`" :disabled="locked(band) || !subject.can_edit"><option v-for="name in subjectChoices(band, subject.name)" :key="name">{{ name }}</option></select></label>
                        <button type="button" class="btn btn-outline-secondary" :disabled="locked(band) || !subject.can_edit || band.subjects.length === 1" @click="band.subjects.splice(s, 1)">Remove subject</button>
                        <button type="button" class="btn btn-outline-secondary" :disabled="locked(band) || !subject.can_edit || s === 0 || !band.subjects[s - 1]?.can_edit" @click="move(band.subjects, s, -1)">Move subject up</button>
                        <button type="button" class="btn btn-outline-secondary" :disabled="locked(band) || !subject.can_edit || s === band.subjects.length - 1 || !band.subjects[s + 1]?.can_edit" @click="move(band.subjects, s, 1)">Move subject down</button>
                    </div>
                    <p v-if="!subject.can_edit" class="small text-muted">Filled in by the teacher assigned to this subject and grade band.</p>
                    <div v-for="(_, c) in subject.criteria" :key="c" class="d-flex gap-2 mb-2 flex-wrap">
                        <input v-model="subject.criteria[c]" class="form-control flex-grow-1 w-auto" :aria-label="`Criterion ${c + 1} for ${subject.name}`" maxlength="200" :disabled="locked(band) || !subject.can_edit">
                        <button type="button" class="btn btn-outline-secondary" :disabled="locked(band) || !subject.can_edit || subject.criteria.length === 1" @click="subject.criteria.splice(c, 1)">Remove criterion</button>
                        <button v-if="c > 0" type="button" class="btn btn-outline-secondary" :disabled="locked(band) || !subject.can_edit" @click="move(subject.criteria, c, -1)">Move criterion up</button>
                        <button v-if="c < subject.criteria.length - 1" type="button" class="btn btn-outline-secondary" :disabled="locked(band) || !subject.can_edit" @click="move(subject.criteria, c, 1)">Move criterion down</button>
                    </div>
                    <button type="button" class="btn btn-outline-secondary" :disabled="locked(band) || !subject.can_edit" @click="subject.criteria.push('')">Add criterion</button>
                </div>
                <div class="d-flex gap-2 mb-3">
                    <select v-model="newSubjects[b]" class="form-select" :aria-label="`New subject for ${band.name}`" :disabled="locked(band)"><option value="">Choose subject</option><option v-for="name in availableSubjects(band)" :key="name">{{ name }}</option></select>
                    <button type="button" class="btn btn-outline-secondary text-nowrap" :disabled="locked(band) || !newSubjects[b]" @click="addSubject(band, b)">Add subject</button>
                </div>
                <h3 class="h6">Learning behaviours</h3>
                <div v-for="(_, c) in band.behaviours" :key="c" class="d-flex gap-2 mb-2 flex-wrap">
                    <input v-model="band.behaviours[c]" class="form-control flex-grow-1 w-auto" :aria-label="`Learning behaviour ${c + 1} in ${band.name}`" maxlength="200" :disabled="locked(band) || !band.can_edit_behaviours">
                    <button type="button" class="btn btn-outline-secondary" :disabled="locked(band) || !band.can_edit_behaviours" @click="band.behaviours.splice(c, 1)">Remove behaviour</button>
                    <button v-if="c > 0" type="button" class="btn btn-outline-secondary" :disabled="locked(band) || !band.can_edit_behaviours" @click="move(band.behaviours, c, -1)">Move behaviour up</button>
                    <button v-if="c < band.behaviours.length - 1" type="button" class="btn btn-outline-secondary" :disabled="locked(band) || !band.can_edit_behaviours" @click="move(band.behaviours, c, 1)">Move behaviour down</button>
                </div>
                <button type="button" class="btn btn-outline-secondary" :disabled="locked(band) || !band.can_edit_behaviours" @click="band.behaviours.push('')">Add behaviour</button>
            </fieldset>
            <p v-if="settings.can_manage_bands" class="small text-muted">Keep bands in grade order with no gaps or overlaps, covering Pre-K through 12th. Split a band to insert a range beside it, then adjust the boundaries. Removing a band extends its neighbour.</p>
            <div class="d-flex gap-2 align-items-center">
                <button v-if="settings.can_manage_bands" type="button" class="btn btn-outline-secondary" :disabled="saving || bands.length >= 14 || (!nextTerm && bands.some(b => b.frozen))" @click="addBand">Add band</button>
                <button type="button" class="btn btn-primary" :disabled="saving" @click="save">Save criteria</button>
                <span v-if="saved" role="status">Saved</span>
            </div>
            <h3 class="h6 mt-3">Recent changes</h3>
            <ul class="small mb-0"><li v-for="(entry, i) in settings.history" :key="i">{{ entry.actor }} · {{ entry.at }} · {{ entry.action }}</li></ul>
        </template>
    </section>
</template>
<script setup lang="ts">
import { ref, onMounted, watch, onBeforeUnmount } from 'vue';
import ApiService from '@/core/services/ApiService';
import type { BackendApiRoute } from '@/core/types/config/BackendApiRoutes';
const props = defineProps<{ schoolId: number; groupId?: number }>();
const settings = ref<any>(null);
const bands = ref<any[]>([]);
const newSubjects = ref<Record<number, string>>({});
const nextTerm = ref(false);
const saving = ref(false);
const saved = ref(false);
const error = ref('');
let generation = 0;
const url = (): BackendApiRoute => props.groupId ? `/api/teacher/masjids/${props.schoolId}/groups/${props.groupId}/report-card-criteria` : `/api/admin/masjids/${props.schoolId}/report-card-criteria`;
const clone = (value: any) => JSON.parse(JSON.stringify(value));
const locked = (band: any) => saving.value || (band.frozen && !nextTerm.value);
const move = (rows: any[], i: number, delta: number) => { const j = i + delta; if (j >= 0 && j < rows.length) [rows[i], rows[j]] = [rows[j], rows[i]]; };
const choices = (band: any) => settings.value.can_manage_bands ? settings.value.subject_names : (band.editable_subject_names ?? band.subjects.filter((s: any) => s.can_edit).map((s: any) => s.name));
const subjectChoices = (band: any, name: string) => [...new Set([name, ...choices(band)])];
const availableSubjects = (band: any) => choices(band).filter((name: string) => !band.subjects.some((s: any) => s.name === name));
const addSubject = (band: any, index: number) => { band.subjects.push({ name: newSubjects.value[index], criteria: [''], can_edit: true }); newSubjects.value[index] = ''; };
const setDefault = (index: number) => { bands.value.forEach((b, i) => { b.is_default = i === index; }); };
const addBand = () => {
    const band = clone(bands.value[bands.value.length - 1]);
    band.id = null; band.name = 'New band'; band.is_default = false; band.frozen = false;
    bands.value.push(band);
};
const splitBand = (index: number) => {
    const band = bands.value[index];
    const nextGrade = settings.value.grades[settings.value.grades.indexOf(band.from_grade) + 1];
    if (!nextGrade || band.from_grade === band.to_grade) return;
    const upper = clone(band);
    upper.id = null; upper.name = `${band.name} (next grades)`; upper.from_grade = nextGrade; upper.is_default = false;
    band.to_grade = band.from_grade;
    bands.value.splice(index + 1, 0, upper);
};
const removeBand = (index: number) => {
    const removed = bands.value[index];
    const neighbour = bands.value[index > 0 ? index - 1 : 1];
    if (index > 0) neighbour.to_grade = removed.to_grade; else neighbour.from_grade = removed.from_grade;
    if (removed.is_default) neighbour.is_default = true;
    bands.value.splice(index, 1);
};
const receive = (data: any) => {
    settings.value = data; nextTerm.value = !!data.draft_bands;
    bands.value = clone(data.draft_bands ?? data.bands);
    if (data.draft_bands) bands.value.forEach(band => {
        const current = data.bands.find((b: any) => b.id === band.id);
        band.frozen = band.frozen ?? current?.frozen ?? false;
        band.can_edit_behaviours = band.can_edit_behaviours ?? current?.can_edit_behaviours ?? data.can_manage_bands;
        band.editable_subject_names = band.editable_subject_names ?? current?.editable_subject_names ?? [];
        band.subjects.forEach((s: any) => { s.can_edit = s.can_edit ?? (data.can_manage_bands || band.editable_subject_names.includes(s.name)); });
    });
    newSubjects.value = {};
};
const startDraft = () => { if (settings.value.draft_bands) receive(settings.value); nextTerm.value = true; saved.value = false; };
const editCurrent = () => { bands.value = clone(settings.value.bands); nextTerm.value = false; saved.value = false; };
const load = async () => {
    const mine = ++generation; settings.value = null; bands.value = []; error.value = ''; saved.value = false; saving.value = false;
    try { const res = await ApiService.get(url()); if (mine === generation) receive(res.data.data); }
    catch { if (mine === generation) error.value = 'Could not load the report-card criteria.'; }
};
const save = async () => {
    const mine = generation; saving.value = true; saved.value = false; error.value = '';
    const body = { revision: settings.value.revision, next_term: nextTerm.value, bands: bands.value.map(b => ({ id: b.id, name: b.name, from_grade: b.from_grade, to_grade: b.to_grade, is_default: b.is_default, subjects: b.subjects.map((s: any) => ({ name: s.name, criteria: [...s.criteria] })), behaviours: [...b.behaviours] })) };
    try { const res = await ApiService.put(url(), body, 'url', { 'Content-Type': 'application/json' }); if (mine === generation) { receive(res.data.data); saved.value = true; } }
    catch (e: any) { if (mine === generation) error.value = Object.values(e?.response?.data?.errors ?? e?.response?.data?.data ?? {}).flat().join(' ') || e?.response?.data?.message || 'Could not save the criteria.'; }
    finally { if (mine === generation) saving.value = false; }
};
watch(() => [props.schoolId, props.groupId], () => { void load(); });
onMounted(load);
onBeforeUnmount(() => { generation++; });
</script>
