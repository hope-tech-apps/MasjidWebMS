<template>
    <div v-for="grade in grades" :key="grade.subject" class="card border-0 shadow-sm mb-2">
        <div class="card-header bg-white fw-semibold small" dir="auto">{{ grade.subject }}</div>
        <div class="card-body small">
            <!-- Owner 2026-10-14: no letter, no term-grade line; the comment alone stays. -->
            <template v-if="grade.final_letter">
                <span>{{ t('term_grade') }}: </span>
                <strong dir="ltr">{{ grade.final_letter }}</strong>
                <span v-if="grade.percentage !== null && grade.percentage !== undefined" dir="ltr"> · {{ grade.percentage }}%</span>
            </template>
            <p v-if="grade.comment" :class="grade.final_letter ? 'mt-1 mb-0' : 'mb-0'" dir="auto">{{ grade.comment }}</p>
        </div>
    </div>
</template>
<script setup lang="ts">
import { useFamilyLang } from '@/views/family/familyI18n';
defineProps<{ grades: any[] }>();
const { t } = useFamilyLang();
</script>
