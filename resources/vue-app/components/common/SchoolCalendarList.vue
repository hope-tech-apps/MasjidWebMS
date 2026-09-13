<template>
    <div>
        <div v-for="month in months" :key="month.key" class="mb-4">
            <h3 class="h6 text-muted fw-semibold mb-2">{{ formatSchoolDay(month.firstDate, locale, { month: 'long', year: 'numeric' }) }}</h3>
            <ul class="list-group">
                <li v-for="day in month.days" :key="day.date"
                    class="list-group-item d-flex flex-wrap align-items-center gap-2"
                    :class="{ 'is-past': day.date < today, 'is-today': day.date === today }"
                    :aria-current="day.date === today ? 'date' : undefined">
                    <span class="fw-semibold small day-date">
                        {{ formatSchoolDay(day.date, locale, { weekday: 'long', month: 'long', day: 'numeric' }) }}
                    </span>
                    <span v-if="day.date === today" class="badge bg-success-subtle text-success-emphasis">{{ labels.today }}</span>

                    <!-- `push-end`, not `ms-auto`: the family portal loads the LTR
                         Bootstrap build, where `.ms-auto` is a physical margin-left
                         and would pin this to the wrong side of an Arabic row. -->
                    <span class="push-end d-inline-flex flex-wrap align-items-center gap-2 small">
                        <template v-if="day.closed">
                            <span class="badge bg-danger-subtle text-danger-emphasis">{{ labels.noSchool }}</span>
                            <!-- The school's own words, in whichever language it wrote them. -->
                            <span v-if="day.reason" dir="auto">{{ day.reason }}</span>
                        </template>
                        <span v-else class="text-muted">{{ labels.schoolDay }}</span>
                    </span>
                </li>
            </ul>
        </div>

        <p v-if="!months.length" class="text-muted small mb-0">{{ labels.empty }}</p>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import {
    SchoolCalendarDay,
    formatSchoolDay,
    monthsOf,
} from '@/core/types/data/masjid-related/SchoolCalendar';

/**
 * A school year's meeting days, month by month, read-only.
 *
 * Shared by the teacher shell and the family portal, which differ only in the
 * words and the locale — so both come in as props and nothing here is worded in
 * one language. The admin screen does not use this: its rows are buttons.
 */
const props = defineProps<{
    days: SchoolCalendarDay[];
    /** Today in the school's time zone ('Y-m-d'). */
    today: string;
    /** Handed to toLocaleDateString — `en-US`, or the family portal's `ar-u-nu-latn`. */
    locale: string;
    labels: { today: string; noSchool: string; schoolDay: string; empty: string };
}>();

const months = computed(() => monthsOf(props.days));
</script>

<style scoped>
.push-end { margin-inline-start: auto; }

/* A day that has passed stays readable — it is dimmed by colour, not opacity,
   so the text keeps its contrast. */
.is-past .day-date { color: var(--bs-secondary-color); }

.is-today { border-inline-start: 3px solid var(--bs-success); }
</style>
