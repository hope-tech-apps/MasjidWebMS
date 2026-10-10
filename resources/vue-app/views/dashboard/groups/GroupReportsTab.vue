<template>
    <div>
        <p v-if="error" class="text-danger small" role="alert">{{ error }}</p>
        <p v-if="loading" class="text-muted small">Loading…</p>
        <div v-if="!card" class="d-flex gap-2 mb-3 flex-wrap">
            <label class="small">Report
                <select v-model="period.type" class="form-select form-select-sm" @change="load">
                    <option value="report_card">Report Card</option><option value="progress">Progress Report</option>
                </select>
            </label>
            <label class="small">School year
                <select v-model="period.school_year" class="form-select form-select-sm" @change="load">
                    <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
                </select>
            </label>
            <label class="small">Quarter
                <select v-model="period.term" class="form-select form-select-sm" @change="load">
                    <option v-for="n in [1, 2, 3, 4]" :key="n" :value="n">Quarter {{ n }}</option>
                </select>
            </label>
        </div>
        <div v-if="!card" class="list-group">
            <div v-for="student in students" :key="student.membership_id" class="list-group-item d-flex gap-3 flex-wrap align-items-center">
                <button type="button" class="btn btn-link p-0" :disabled="!student.started || loading" @click="open(student)">{{ name(student) }}</button>
                <span class="small text-muted">{{ student.started ? `${student.assessed} of ${student.criteria} marked` : 'Not started' }}</span>
                <span v-if="student.published" class="small">Sent to the family</span>
                <span v-if="student.left_on" class="small text-muted">Left {{ student.left_on }}</span>
            </div>
        </div>
        <div v-else>
            <button type="button" class="btn btn-link px-0 mb-2" @click="card = null">All students</button>
            <h4 class="h6">{{ name(card.student) }}</h4>
            <p class="small text-muted">{{ card.type_label }} · {{ card.period_label }}</p>
            <PerformanceLevelHelp :levels="levels" />
            <div v-for="subject in card.subjects" :key="subject.subject" class="card border-0 shadow-sm mb-2">
                <div class="card-header bg-white small fw-semibold">{{ subject.subject }}</div>
                <ReportSubjectSummary v-if="subject.work_summary" :summary="subject.work_summary" :levels="levels" :href="subjectHref(subject.work_summary.class_subject_id)" />
                <div v-for="line in subject.criteria" :key="line.id" class="list-group-item small d-flex gap-3 flex-wrap px-3 py-2">
                    <span>{{ line.criterion }}</span><span>{{ line.level_label ?? 'Not assessed' }}</span><span v-if="line.comment">{{ line.comment }}</span>
                </div>
            </div>
            <div v-if="card.learning_behaviours.length" class="card border-0 shadow-sm mb-2">
                <div class="card-header small fw-semibold">Learning behaviours</div>
                <div v-for="line in card.learning_behaviours" :key="line.id" class="list-group-item small px-3 py-2">{{ line.criterion }} · {{ line.level_label ?? 'Not assessed' }} <span v-if="line.comment">{{ line.comment }}</span></div>
            </div>
            <p v-if="card.teacher_comment" class="small">{{ card.teacher_comment }}</p>
            <p v-if="card.published" class="small">Present {{ card.attendance.present }} (includes {{ card.attendance.late }} late) · Absent {{ card.attendance.absent }}</p>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ApiService from '@/core/services/ApiService';
import PerformanceLevelHelp from '@/components/classes/PerformanceLevelHelp.vue';
import ReportSubjectSummary from '@/components/classes/ReportSubjectSummary.vue';

const props = defineProps<{ base: string }>();
const route = useRoute();
const router = useRouter();
const period = ref({ type: 'report_card', school_year: '', term: 1 });
const schoolYears = ref<string[] | null>(null);
const students = ref<any[]>([]);
const card = ref<any>(null);
const levels = ref<any[]>([]);
const loading = ref(false);
const error = ref('');
let generation = 0;
const years = computed(() => {
    const year = Number(period.value.school_year.slice(0, 4));
    return [...new Set([...(schoolYears.value ?? (year ? [year - 1, year, year + 1].map(n => `${n}-${n + 1}`) : [])), period.value.school_year].filter(Boolean))].sort().reverse();
});
const name = (student: any): string => [student.contact?.first_name, student.contact?.last_name].filter(Boolean).join(' ') || 'Student';
const query = () => new URLSearchParams({ type: period.value.type, term: String(period.value.term), ...(period.value.school_year ? { school_year: period.value.school_year } : {}) }).toString();
const subjectHref = (id: number) => router.resolve({ path: route.path, query: { subject: String(id) } }).href;
const load = async () => {
    const mine = ++generation; loading.value = true; error.value = '';
    try {
        const res = await ApiService.get(`${props.base}/report-cards?${query()}`);
        if (mine !== generation) return;
        students.value = res.data.data.students;
        period.value = res.data.data.period;
        schoolYears.value = res.data.data.school_years ?? null;
        levels.value = res.data.performance_levels ?? [];
    } catch { if (mine === generation) error.value = 'Could not load the report cards.'; }
    finally { if (mine === generation) loading.value = false; }
};
const open = async (student: any) => {
    if (!student.started || loading.value) return;
    const mine = ++generation; loading.value = true; error.value = '';
    try {
        const res = await ApiService.get(`${props.base}/members/${student.membership_id}/report-card?${query()}`);
        if (mine !== generation) return;
        card.value = res.data.data; levels.value = res.data.performance_levels ?? levels.value;
    } catch { if (mine === generation) error.value = 'Could not open that report card.'; }
    finally { if (mine === generation) loading.value = false; }
};
onMounted(load);
onBeforeUnmount(() => { generation++; });
</script>
