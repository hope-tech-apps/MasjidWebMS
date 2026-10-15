<template>
    <article v-if="document" class="transcript-document">
        <img v-if="document.school?.logo" :src="document.school.logo" :alt="document.school.name" style="max-height: 64px; max-width: 160px" />
        <h2 class="h5" dir="auto">{{ document.school?.name }} · {{ words[document.designation === 'Official' ? 'official' : 'unofficial'] }}</h2>
        <p dir="auto">{{ document.school?.address }}</p>
        <h3 class="h6" dir="auto">{{ document.student?.name }}</h3>
        <p v-if="document.student?.date_of_birth">{{ words.date_of_birth }}: {{ transcriptDate(document.student.date_of_birth) }}</p>
        <p>{{ words.attended }}: {{ document.student?.attended_from ? transcriptDate(document.student.attended_from) : words.none }} to {{ document.student?.attended_to ? transcriptDate(document.student.attended_to) : words.present }}</p>
        <section v-for="(year, i) in document.years" :key="i" class="mb-4">
            <h3 class="h6" dir="auto">{{ year.year }} · {{ transcriptGrade(year.grade) }} · {{ year.origin?.startsWith('From ') ? `${words.from} ${year.origin.slice(5)}` : year.origin }}</h3>
            <p v-if="year.excluded_from_gpa">{{ words.file_only }}</p>
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ words.course }}</th><th>{{ words.level }}</th><th>{{ words.final }}</th><th>{{ words.attempted }}</th><th>{{ words.earned }}</th></tr></thead>
                <tbody><tr v-for="(course, j) in year.courses.filter(c => c.on_transcript !== false)" :key="j"><td dir="auto">{{ course.name }}</td><td>{{ words[course.level] }}</td><td>{{ course.letter || words.not_graded }}</td><td>{{ course.letter ? (course.credit ?? words.none) : '' }}</td><td>{{ course.letter ? (course.passing ? (course.credit ?? words.none) : '0.00') : '' }}</td></tr></tbody></table></div>
            <p>{{ words.plain }}: {{ year.totals.plain ?? words.none }} · {{ words.weighted }}: {{ year.totals.weighted ?? words.none }}<br>{{ words.attempted }}: {{ year.totals.attempted ?? '0.00' }} · {{ words.earned }}: {{ year.totals.earned ?? '0.00' }}</p>
        </section>
        <h3 class="h6">{{ words.cumulative }}</h3>
        <p>{{ words.plain }}: {{ document.cumulative?.plain ?? words.none }} · {{ words.weighted }}: {{ document.cumulative?.weighted ?? words.none }}<br>{{ words.attempted }}: {{ document.cumulative?.attempted ?? '0.00' }} · {{ words.earned }}: {{ document.cumulative?.earned ?? '0.00' }}</p>
    </article>
</template>
<script setup lang="ts">
import { transcriptDate, transcriptGrade } from '@/core/helpers/transcriptDisplay';
import { computed } from 'vue';
const props = defineProps<{ document: any; labels?: Record<string, string> }>();
const defaults: Record<string, string> = { present:'Present',not_graded:'Not graded',official:'Official',unofficial:'Unofficial',date_of_birth:'Date of birth',attended:'Dates attended',none:'Not recorded',from:'From',file_only:'Prior transcript file only. No courses entered; this year contributes no credits or GPA.',course:'Course',level:'Level',final:'Final letter',attempted:'Credits attempted',earned:'Credits earned',plain:'Plain GPA',weighted:'Weighted GPA',cumulative:'Cumulative',regular:'Regular',honors:'Honors',ap:'AP' };
const words = computed(() => ({ ...defaults, ...props.labels }));
</script>
