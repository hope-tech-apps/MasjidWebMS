<template>
    <section class="family-attendance" :dir="dir" :lang="lang" :aria-label="t('tab_attendance')">
        <article v-for="child in children" :key="child.membership_id" class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 text-break" dir="auto">{{ childName(child) }}</h2>
                <div v-if="errors[child.membership_id]" class="alert alert-danger" role="alert">
                    {{ t('attendance_error') }}
                    <button type="button" class="btn btn-sm btn-outline-danger" @click="load">{{ t('cal_retry') }}</button>
                </div>
                <p v-else-if="!records[child.membership_id]" class="text-muted small" role="status">{{ t('cal_loading') }}</p>
                <template v-else>
                    <div class="small text-muted mb-2">
                        <span dir="auto">{{ record(child).period.kind === 'term' ? record(child).period.name : t('attendance_year') }}</span>
                        · {{ date(record(child).period.from) }} – {{ date(record(child).period.to) }}
                    </div>
                    <p class="d-flex flex-wrap gap-2 mb-3">
                        <span v-for="status in statuses" :key="status">{{ t('attendance_mark_' + status) }} {{ record(child).totals[status] }}</span>
                    </p>
                    <p v-if="!record(child).days.length" class="text-muted small mb-0">{{ t('attendance_empty') }}</p>
                    <ul v-else class="list-group list-group-flush">
                        <li v-for="day in record(child).days" :key="`${day.date}-${day.class.id}`" class="list-group-item px-0 attendance-day">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                            <span>{{ date(day.date) }}</span>
                            <span dir="auto">{{ day.class.name }}</span>
                            <span class="fw-semibold">{{ t('attendance_mark_' + day.status) }}</span>
                            </div>
                            <div v-if="day.periods?.length" class="small mt-2">
                                <span class="text-muted">{{ t('attendance_periods') }}</span>
                                <ul class="mb-0">
                                    <li v-for="period in day.periods" :key="period.meeting_id" class="d-flex flex-wrap justify-content-between gap-2">
                                        <span dir="auto">{{ period.period }} · {{ period.subject }}</span>
                                        <span class="fw-semibold">{{ t('attendance_mark_' + period.status) }}</span>
                                    </li>
                                </ul>
                            </div>
                        </li>
                    </ul>
                </template>
            </div>
        </article>
    </section>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import FamilyApiService from '@/core/services/FamilyApiService';
import { useFamilyLang } from '@/views/family/familyI18n';
import { formatSchoolDay } from '@/core/types/data/masjid-related/SchoolCalendar';

const props = defineProps<{ masjidId: string; groupId: string; children: any[] }>();
const { t, lang, dir, locale } = useFamilyLang();
const statuses = ['present', 'late', 'absent', 'excused'];
const records = ref<Record<number, any>>({});
const errors = ref<Record<number, boolean>>({});
const childName = (child: any) => [child.contact?.first_name, child.contact?.last_name].filter(Boolean).join(' ');
const record = (child: any) => records.value[child.membership_id];
const date = (iso: string) => formatSchoolDay(iso, locale.value, { month: 'long', day: 'numeric', year: 'numeric' });
let generation = 0; let alive = true;

async function load() {
    const run = ++generation;
    // Freeze the URL and roster so a school switch cannot send the next child's request under a new credential.
    const base = `/api/family/masjids/${props.masjidId}/groups/${props.groupId}`;
    const children = [...props.children];
    records.value = {}; errors.value = {};
    for (const child of children) {
        if (!alive || run !== generation) return;
        try {
            const response = await FamilyApiService.get(`${base}/members/${child.membership_id}/attendance`);
            if (!alive || run !== generation) return;
            records.value[child.membership_id] = response.data.data;
        } catch {
            if (!alive || run !== generation) return;
            errors.value[child.membership_id] = true;
        }
    }
}
watch(() => [props.masjidId, props.groupId, props.children], load, { immediate: true });
onBeforeUnmount(() => { alive = false; ++generation; });
</script>

<style scoped>
.family-attendance, .card-body, .attendance-day, .attendance-day span { min-width: 0; max-width: 100%; }
.attendance-day { text-align: start; overflow-wrap: anywhere; }
</style>
