<template>
    <section v-if="subjects.length" class="family-subjects">
        <h3 class="text-uppercase text-muted small">{{ t('subject_work_heading') }}</h3>
        <article v-for="subject in subjects" :key="subject.id" class="border rounded p-2 mb-2">
            <h4 class="h6" dir="auto">{{ subject.name }}</h4>
            <div v-for="mark in subject.marks" :key="mark.id" class="mb-2">
                <strong class="d-block" dir="auto">{{ mark.title }}</strong>
                <span v-if="mark.level !== null">{{ mark.level }} {{ t(`level_short_${mark.level}`) }}</span>
                <p v-if="mark.comment" class="subject-text mb-1" dir="auto">{{ text('mark', mark, mark.comment) }}</p>
                <time class="small text-muted d-block" :datetime="mark.date">{{ when(mark.date) }}</time>
            </div>
            <div v-for="note in subject.notes" :key="note.id" class="mb-2">
                <p class="subject-text mb-1" dir="auto">{{ text('note', note, note.body) }}</p>
                <time class="small text-muted d-block" :datetime="note.date">{{ when(note.date) }}</time>
            </div>
        </article>
    </section>
</template>
<script setup lang="ts">
import { useFamilyLang } from '@/views/family/familyI18n';
const props = defineProps<{ subjects: any[]; memberId: number; tx?: (key: string, original: string) => string; keyOf?: (member: number, kind: string, item: any) => string }>();
const { t, locale } = useFamilyLang();
const text = (kind: string, item: any, original: string) => props.tx && props.keyOf ? props.tx(props.keyOf(props.memberId, kind, item), original) : original;
const when = (date: string) => new Date(date).toLocaleDateString(locale.value, { year: 'numeric', month: 'short', day: 'numeric' });
</script>
<style scoped>
.family-subjects { min-width: 0; max-width: 100%; overflow-wrap: anywhere; text-align: start; }
.subject-text { white-space: pre-wrap; }
article, strong, p { min-width: 0; }
</style>
