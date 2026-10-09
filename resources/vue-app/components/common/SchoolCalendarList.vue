<template>
    <div>
        <div v-for="month in months" :key="month.key" class="mb-4">
            <button v-if="collapsible" type="button" class="btn w-100 month-toggle" :aria-expanded="expandedMonths[month.key] ? 'true' : 'false'" @click="expandedMonths[month.key] = !expandedMonths[month.key]">
                <span>{{ formatSchoolDay(month.firstDate, locale, { month: 'long', year: 'numeric' }) }} · {{ labels.schoolDays }}: {{ schoolMonthCounts(month).open }} · {{ labels.withNoSchool }}: {{ schoolMonthCounts(month).closed }}</span>
                <span class="month-arrow" aria-hidden="true"></span>
            </button>
            <h3 v-else class="h6 text-muted fw-semibold mb-2">{{ formatSchoolDay(month.firstDate, locale, { month: 'long', year: 'numeric' }) }}</h3>
            <ul v-if="!collapsible || expandedMonths[month.key]" class="list-group" :class="{ 'mt-2': collapsible }">
                <li v-for="day in month.days" :key="day.date"
                    class="list-group-item d-flex flex-wrap align-items-center gap-2"
                    :class="{ 'is-past': day.date < today, 'is-today': day.date === today }"
                    :aria-current="day.date === today ? 'date' : undefined">
                    <span class="fw-semibold small day-date" :class="{ 'text-break': collapsible }">
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
                            <span v-if="day.reason" dir="auto" :class="{ 'text-break': collapsible }">{{ day.reason }}</span>
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
import { computed, ref, watch } from 'vue';
import {
    SchoolCalendarDay,
    formatSchoolDay,
    monthsOf,
    schoolMonthCounts,
} from '@/core/types/data/masjid-related/SchoolCalendar';

/**
 * A school year's meeting days, month by month, read-only.
 *
 * Shared by the teacher shell and the family portal, which differ only in the
 * words and the locale — so both come in as props and nothing here is worded in
 * one language. Grouping and counts are shared with the office; its day rows are buttons.
 */
const props = defineProps<{
    days: SchoolCalendarDay[];
    /** Today in the school's time zone ('Y-m-d'). */
    today: string;
    /** Handed to toLocaleDateString — `en-US`, or the family portal's `ar-u-nu-latn`. */
    locale: string;
    labels: { today: string; noSchool: string; schoolDay: string; empty: string; schoolDays?: string; withNoSchool?: string };
    /** Only configured calendars start closed; legacy lists remain fully visible. */
    collapsible?: boolean;
}>();

const months = computed(() => monthsOf(props.days));
const expandedMonths = ref<Record<string, boolean>>({});
watch(() => props.days, () => { expandedMonths.value = {}; });
</script>

<style scoped>
/* Drawn as a bordered box with an arrow that turns, so it reads as something to
   press on the family page's tinted background as well as the teacher's white one.
   Label first, then the number: a count before a noun cannot be grammatical in
   every language the family portal speaks. */
.month-toggle {
    display: flex; align-items: center; gap: 0.75rem;
    text-align: start; white-space: normal; overflow-wrap: anywhere; min-height: 44px;
    background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); color: var(--bs-body-color);
}
.month-toggle:hover, .month-toggle:focus-visible { background: var(--bs-tertiary-bg); border-color: var(--bs-secondary-color); }
.month-arrow {
    flex: none; margin-inline-start: auto; width: 0.55rem; height: 0.55rem;
    border-inline-end: 2px solid currentColor; border-block-end: 2px solid currentColor;
    transform: rotate(45deg); transition: transform 0.15s ease;
}
.month-toggle[aria-expanded="true"] .month-arrow { transform: rotate(-135deg); }
@media (prefers-reduced-motion: reduce) { .month-arrow { transition: none; } }
.push-end { margin-inline-start: auto; }

/* A day that has passed stays readable — it is dimmed by colour, not opacity,
   so the text keeps its contrast. */
.is-past .day-date { color: var(--bs-secondary-color); }

.is-today { border-inline-start: 3px solid var(--bs-success); }
</style>
